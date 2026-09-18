<?php

namespace App\Services;

use App\Integrations\Gateway;
use App\Models\ContentRefreshCandidate;
use App\Models\User;
use App\Models\Website;
use DomainException;
use Illuminate\Database\Eloquent\Collection;
use Throwable;

/**
 * İçerik yenileme (faz 60e): yayındaki içerikler arasında yenileme adaylarını deterministik sinyallerle bulur —
 * eskime (yayın > 12 ay ve güncelleme > 6 ay), gövdede eski yıl vurgusu, kırık iç bağlantı (teknik denetim),
 * tıklama düşüşü (yalnız Search Console bağlıysa), erişilemeyen dış kaynak (yalnız --external ile, Gateway::probe).
 * Aday kaydı saklanır; AI ile yenileme yayındaki metni değil ÇALIŞMA TASLAĞINI üretir.
 */
class ContentRefreshService
{
    public const STALE_MONTHS = 12;

    public const UNTOUCHED_MONTHS = 6;

    public function __construct(
        private readonly ContentService $contents,
        private readonly SeoService $seo,
        private readonly SearchPerformanceService $performance,
        private readonly Gateway $gateway,
        private readonly AuditService $audit,
    ) {}

    /**
     * Tespit: adaylar yazılır/güncellenir; artık sinyali olmayan açık adaylar kapanır (done). Döner: aday sayısı.
     */
    public function detect(Website $website, bool $external = false): int
    {
        $broken = [];

        foreach ($this->seo->technicalReport($website)['broken_links']['items'] as $item) {
            if (preg_match('~\((/[^)\s]*)\)~', $item, $m) === 1) {
                $broken[$m[1]] = ($broken[$m[1]] ?? 0) + 1;
            }
        }

        $trend = $this->performance->pageClickTrend($website);
        $currentYear = (int) now()->format('Y');
        $found = [];

        foreach ($this->contents->livePosts($website, 1000)->merge($this->contents->livePages($website)) as $content) {
            $reasons = [];
            $score = 0;
            $published = $content->published_at;
            $updated = $content->updated_at;

            if ($published !== null && $published->lt(now()->subMonths(self::STALE_MONTHS)) && ($updated === null || $updated->lt(now()->subMonths(self::UNTOUCHED_MONTHS)))) {
                $reasons[] = ['key' => 'stale', 'label' => 'Eski içerik: '.$published->diffInMonths(now()).' ay önce yayınlandı, '.($updated?->diffInMonths(now()) ?? '—').' aydır güncellenmedi'];
                $score += 3;
            }

            if (preg_match_all('/\b(20\d{2})\b/', (string) $content->body, $years) > 0) {
                $old = array_values(array_unique(array_filter(array_map('intval', $years[1]), fn (int $y) => $y < $currentYear - 1)));

                if ($old !== []) {
                    $reasons[] = ['key' => 'outdated_year', 'label' => 'Gövdede eski yıl vurgusu: '.implode(', ', $old)];
                    $score += 1;
                }
            }

            if (isset($broken[$content->path()])) {
                $reasons[] = ['key' => 'broken_links', 'label' => $broken[$content->path()].' kırık iç bağlantı'];
                $score += 2;
            }

            $t = $trend[$content->path()] ?? null;

            if ($t !== null && $t['before'] >= 20 && $t['now'] <= $t['before'] / 2) {
                $reasons[] = ['key' => 'traffic_drop', 'label' => 'Search Console tıklaması düştü: '.$t['before'].' → '.$t['now']];
                $score += 3;
            }

            if ($external) {
                $dead = $this->deadExternalLinks((string) $content->body);

                if ($dead !== []) {
                    $reasons[] = ['key' => 'dead_sources', 'label' => 'Erişilemeyen dış kaynak: '.implode(', ', array_slice($dead, 0, 3))];
                    $score += 2;
                }
            }

            if ($reasons === []) {
                continue;
            }

            $found[$content->id] = true;
            $candidate = ContentRefreshCandidate::query()->firstOrNew(['website_id' => $website->id, 'content_id' => $content->id]);
            $candidate->reasons = $reasons;
            $candidate->score = min(10, $score);
            $candidate->detected_at = now();

            if (! $candidate->exists) {
                $candidate->status = 'open';
            }

            $candidate->save();
        }

        // Sinyali kalmayan açık adaylar kapanır.
        ContentRefreshCandidate::query()->where('website_id', $website->id)->whereIn('status', ['open', 'planned'])->whereNotIn('content_id', array_keys($found))->update(['status' => 'done']);

        return count($found);
    }

    /** @return Collection<int, ContentRefreshCandidate> */
    public function candidates(Website $website, string $status = 'open'): Collection
    {
        return ContentRefreshCandidate::query()->where('website_id', $website->id)->when($status !== 'all', fn ($q) => $q->where('status', $status))->with('content')->orderByDesc('score')->orderByDesc('detected_at')->get();
    }

    public function find(Website $website, int $id): ?ContentRefreshCandidate
    {
        return ContentRefreshCandidate::query()->where('website_id', $website->id)->with('content')->find($id);
    }

    public function decide(User $actor, Website $website, int $id, string $status): ContentRefreshCandidate
    {
        if (! isset(ContentRefreshCandidate::STATUSES[$status])) {
            throw new DomainException('Geçersiz durum.');
        }

        $candidate = $this->find($website, $id);

        if ($candidate === null) {
            throw new DomainException('Aday bulunamadı.');
        }

        $candidate->status = $status;
        $candidate->decided_by = $actor->id;
        $candidate->save();
        $this->audit->record($actor, 'refresh.decided', 'content_refresh_candidate', $candidate->id, [], ['status' => $status, 'content_id' => $candidate->content_id]);

        return $candidate;
    }

    /** Adaya AI işi bağlar (planlandı). */
    public function attachJob(User $actor, ContentRefreshCandidate $candidate, int $jobId): void
    {
        $candidate->ai_job_id = $jobId;
        $candidate->status = 'planned';
        $candidate->decided_by = $actor->id;
        $candidate->save();
    }

    /**
     * Dış bağlantı yoklaması (yalnız açıkça istenince; Gateway::probe SSRF korumalı).
     *
     * @return list<string>
     */
    private function deadExternalLinks(string $body): array
    {
        preg_match_all('/\]\((https?:\/\/[^)\s]+)\)/', $body, $m);
        $dead = [];

        foreach (array_slice(array_unique($m[1]), 0, 10) as $url) {
            try {
                $status = $this->gateway->probe($url);
            } catch (Throwable) {
                continue;
            }

            if ($status === null || $status >= 400) {
                $dead[] = (string) (parse_url($url, PHP_URL_HOST) ?: $url);
            }
        }

        return $dead;
    }
}

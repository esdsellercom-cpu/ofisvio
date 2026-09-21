<?php

namespace App\Services;

use App\Enums\ContentStatus;
use App\Models\ConsentRecord;
use App\Models\Content;
use App\Models\LegalDocumentVersion;
use App\Models\User;
use App\Models\Website;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Yasal metin yaşam döngüsü (audit F-07, KVKK). Yasal sayfalar CMS'te yaşar; footer ayarındaki seçim (`legal.kvkk`
 * vb.) hangi sayfanın hangi metin olduğunu söyler. Sürüm = seçilen sayfanın YAYINDAKİ gövdesinin sha256'sı; özet
 * değişince yeni, değişmez sürüm satırı açılır (footer yayını ve içerik yeniden yayını tetikler). Vitrin formundaki
 * her rıza o anki sürüme bağlanır (ConsentRecord); sürüm yoksa kayıt yine düşer (hash null) ve doctor üretimde uyarır.
 * Yalnız bu servis yazar.
 */
class LegalDocumentService
{
    public function __construct(private readonly AuditService $audit, private readonly SiteChromeService $chrome, private readonly ContentService $contents) {}

    /** Footer'da seçili yasal sayfalar için değişen özetlere yeni sürüm açar; dönen: açılan sürüm sayısı. */
    public function syncFromFooter(?User $actor, Website $website, ?string $note = null): int
    {
        $legal = (array) ($this->chrome->config($website, 'footer')['legal'] ?? []);
        $n = 0;

        foreach (LegalDocumentVersion::KINDS as $kind => $label) {
            $id = (int) ($legal[$kind] ?? 0);

            if ($id <= 0) {
                continue;
            }

            $content = Content::query()->where('website_id', $website->id)->whereKey($id)->first();

            if ($content !== null && $this->isLive($content) && $this->publishIfChanged($actor, $website, $kind, $content, $note) !== null) {
                $n++;
            }
        }

        return $n;
    }

    /** İçerik yeniden yayınlandı: footer'da yasal sayfa olarak seçiliyse ilgili tür için sürüm denetlenir. */
    public function syncFromContent(?User $actor, Content $content): int
    {
        $website = $content->website;

        if ($website === null) {
            return 0;
        }

        $legal = (array) ($this->chrome->config($website, 'footer')['legal'] ?? []);
        $n = 0;

        foreach ($legal as $kind => $id) {
            if ((int) $id === $content->id && isset(LegalDocumentVersion::KINDS[$kind]) && $this->publishIfChanged($actor, $website, (string) $kind, $content, 'İçerik yeniden yayınlandı') !== null) {
                $n++;
            }
        }

        return $n;
    }

    /** Özet değiştiyse yeni sürüm (append-only); aynıysa null. */
    public function publishIfChanged(?User $actor, Website $website, string $kind, Content $content, ?string $note = null): ?LegalDocumentVersion
    {
        $hash = hash('sha256', (string) $content->body);
        $current = $this->current($website, $kind);

        if ($current !== null && $current->content_hash === $hash && $current->content_id === $content->id) {
            return null;
        }

        return DB::transaction(function () use ($actor, $website, $kind, $content, $hash, $current, $note) {
            $version = LegalDocumentVersion::create([
                'website_id' => $website->id,
                'kind' => $kind,
                'version' => ($current !== null ? $current->version : 0) + 1,
                'content_id' => $content->id,
                'title' => mb_substr((string) $content->title, 0, 200),
                'content_hash' => $hash,
                'published_at' => now(),
                'published_by' => $actor?->id,
                'note' => $note !== null ? mb_substr($note, 0, 200) : null,
            ]);
            $this->audit->record($actor, 'legal.version_published', 'legal_document_version', $version->id, ['version' => $current?->version, 'hash' => $current?->content_hash], ['kind' => $kind, 'version' => $version->version, 'hash' => $hash, 'content_id' => $content->id]);

            return $version;
        });
    }

    public function current(Website $website, string $kind): ?LegalDocumentVersion
    {
        return LegalDocumentVersion::query()->where('website_id', $website->id)->where('kind', $kind)->orderByDesc('version')->first();
    }

    /** @return Collection<int, LegalDocumentVersion> */
    public function versions(Website $website): Collection
    {
        return LegalDocumentVersion::query()->where('website_id', $website->id)->orderBy('kind')->orderByDesc('version')->get();
    }

    /**
     * Vitrin rızası: konu (lead/booking/…) o anki KVKK sürümüne bağlanır. Website verilmezse varsayılan site.
     *
     * @param  array{ip?: ?string, user_agent?: ?string}  $consent
     */
    public function record(string $subjectType, int $subjectId, array $consent, string $kind = 'kvkk', ?Website $website = null): ConsentRecord
    {
        $website ??= $this->contents->defaultWebsiteOrNull();
        $version = $website !== null ? $this->current($website, $kind) : null;

        return ConsentRecord::create([
            'legal_document_version_id' => $version?->id,
            'kind' => $kind,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'content_hash' => $version?->content_hash,
            'ip' => isset($consent['ip']) ? mb_substr((string) $consent['ip'], 0, 45) : null,
            'user_agent' => isset($consent['user_agent']) ? mb_substr((string) $consent['user_agent'], 0, 255) : null,
            'accepted_at' => now(),
        ]);
    }

    private function isLive(Content $content): bool
    {
        return $content->status === ContentStatus::PUBLISHED && $content->published_at !== null && $content->published_at->lte(now());
    }

    /** Doctor: üretimde KVKK sürümü yoksa rızalar metne bağlanamaz. */
    public function hasCurrent(Website $website, string $kind = 'kvkk'): bool
    {
        return $this->current($website, $kind) !== null;
    }
}

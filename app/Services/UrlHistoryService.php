<?php

namespace App\Services;

use App\Models\ContentUrlHistory;
use App\Models\NotFoundLog;
use App\Models\UrlRedirect;
use App\Models\User;
use App\Models\Website;
use DomainException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * URL geçmişi + yönlendirme tablosu yazıcısı (faz 54). İçerik/hizmet/lokasyon servisleri slug değişince ve silince
 * buraya haber verir; yönlendirme kayıtları YALNIZ buradan yazılır: zincir düzleştirme (A→B, B→C ⇒ A→C ve A'ya
 * gelenler yeni hedefe), döngü reddi (A→B→A), aynı yola tek kayıt. Okuma tarafı ContentCache ile önbelleklidir.
 * Benzerlik/öneri/404 mantığı RedirectService'te (bu sınıf içerik servislerine bağımlı DEĞİLDİR — döngü olmasın).
 */
class UrlHistoryService
{
    public const MAX_DEPTH = 8;

    public function __construct(
        private readonly ContentCache $cache,
        private readonly AuditService $audit,
        private readonly SeoSettingsService $settings,
    ) {}

    // ---- Geçmiş ----------------------------------------------------------------

    /**
     * Slug/üst sayfa değişimi: eski yol geçmişe yazılır ve eski → yeni kalıcı yönlendirme kurulur (eski bağlantılar boşa düşmez).
     */
    public function recordMove(Website $website, string $entityType, int $entityId, string $oldPath, string $newPath, string $reason = 'slug_change', ?User $actor = null): void
    {
        $oldPath = $this->normalizePath($oldPath);
        $newPath = $this->normalizePath($newPath);

        if ($oldPath === $newPath || $oldPath === '/' || $newPath === '/') {
            return;
        }

        ContentUrlHistory::query()->create(['website_id' => $website->id, 'entity_type' => $entityType, 'entity_id' => $entityId, 'old_path' => $oldPath, 'new_path' => $newPath, 'reason' => $reason]);

        // Yeni yola dönmüş bir "eski yol" varsa (geri alınan slug) o kaydı kaldır: A→B iken B→A yazmak döngü olurdu.
        if (UrlRedirect::query()->where('website_id', $website->id)->where('from_path', $newPath)->delete() > 0) {
            $this->cache->invalidate($website); // harita memo'su yenilensin; aksi halde döngü sanılır
        }

        $this->save($website, ['from_path' => $oldPath, 'to_path' => $newPath, 'source' => 'slug_change', 'status' => 'active', 'note' => 'Adres değişti ('.(ContentUrlHistory::REASONS[$reason] ?? $reason).').'], $actor);
    }

    /**
     * Silme: yol geçmişe düşer (anlık görüntüyle: başlık/kategori/etiket/özet → sonraki eşleştirme girdisi); admin seçtiyse
     * yönlendirme kurulur. Seçmediyse kayıt yalnız geçmişte kalır; 404 geldiğinde RedirectService eşleşme arar.
     *
     * @param  array<string, mixed>  $snapshot
     */
    public function recordDeletion(Website $website, string $entityType, int $entityId, string $path, array $snapshot, ?string $redirectTo = null, ?User $actor = null, string $reason = 'deleted'): void
    {
        $path = $this->normalizePath($path);

        ContentUrlHistory::query()->create(['website_id' => $website->id, 'entity_type' => $entityType, 'entity_id' => $entityId, 'old_path' => $path, 'new_path' => $redirectTo !== null && $redirectTo !== '' ? $this->normalizeTarget($redirectTo) : null, 'reason' => $reason, 'snapshot' => $snapshot]);

        // Silinen yola gelen yönlendirmeler artık kırık: hedef verildiyse oraya taşınır, verilmediyse pasife alınır.
        $incoming = UrlRedirect::query()->where('website_id', $website->id)->where('to_path', $path)->get();

        if ($redirectTo !== null && $redirectTo !== '') {
            $this->save($website, ['from_path' => $path, 'to_path' => $redirectTo, 'source' => 'deleted', 'status' => 'active', 'note' => 'İçerik silindi; admin seçimi.'], $actor);
        } else {
            foreach ($incoming as $redirect) {
                $redirect->forceFill(['status' => 'disabled', 'note' => trim((string) $redirect->note.' Hedef silindi.')])->save();
            }

            $this->cache->invalidate($website);
        }
    }

    /** Adres yeniden yayına girdi: hedefi bu yol olduğu için pasife alınmış yönlendirmeler yeniden etkin (faz 54). */
    public function recordRestore(Website $website, string $path): void
    {
        $path = $this->normalizePath($path);
        $n = UrlRedirect::query()->where('website_id', $website->id)->where('to_path', $path)->where('status', 'disabled')->where('note', 'like', '%Hedef silindi.%')->update(['status' => 'active']);
        // Canlı adres yönlendirme kaynağı olamaz (aksi halde middleware sayfayı gölgeler): bu yoldan çıkan kayıtlar kalkar.
        $n += UrlRedirect::query()->where('website_id', $website->id)->where('from_path', $path)->delete();

        if ($n > 0) {
            $this->cache->invalidate($website);
        }
    }

    /**
     * Bir yolun bilinen geçmişi (en yeni önce).
     *
     * @return Collection<int, ContentUrlHistory>
     */
    public function historyFor(Website $website, string $path): Collection
    {
        return ContentUrlHistory::query()->where('website_id', $website->id)->where('old_path', $this->normalizePath($path))->orderByDesc('id')->get();
    }

    // ---- Yönlendirme kayıtları ----------------------------------------------------

    /**
     * Kayıt oluşturur/günceller. Zincir düzleştirme + döngü reddi burada; hedef başka bir yönlendirmenin kaynağıysa
     * son hedefe yazılır, kaynağa gelen yönlendirmeler de yeni hedefe çevrilir.
     *
     * @param  array{from_path: string, to_path: string, code?: int|string|null, status?: string|null, source?: string|null, score?: int|null, note?: string|null}  $data
     */
    public function save(Website $website, array $data, ?User $actor = null, ?UrlRedirect $existing = null): UrlRedirect
    {
        $from = $this->normalizePath((string) $data['from_path']);
        $to = $this->normalizeTarget((string) $data['to_path']);
        $code = (int) ($data['code'] ?? 0) ?: (int) $this->settings->string($website, 'redirect.default_code') ?: 301;
        $status = (string) ($data['status'] ?? 'active');
        $source = (string) ($data['source'] ?? 'manual');

        if ($from === '/' || $from === '') {
            throw new DomainException('Ana sayfa yönlendirilemez.');
        }

        if (! isset(UrlRedirect::CODES[$code])) {
            throw new DomainException('Yönlendirme kodu 301, 302, 307 ya da 308 olmalı.');
        }

        if (! isset(UrlRedirect::STATUSES[$status]) || ! isset(UrlRedirect::SOURCES[$source])) {
            throw new DomainException('Geçersiz durum ya da kaynak.');
        }

        if ($from === $to) {
            throw new DomainException('Kaynak ve hedef aynı olamaz.');
        }

        // Zincir düzleştirme: hedef başka bir etkin yönlendirmenin kaynağıysa son hedefe git; yol kaynağa dönerse döngü.
        if ($status === 'active' && str_starts_with($to, '/')) {
            $final = $this->finalTarget($website, $to, [$from]);

            if ($final === null) {
                throw new DomainException('Bu hedef bir yönlendirme döngüsü oluşturur (A → B → A).');
            }

            $to = $final;
        }

        $redirect = $existing ?? UrlRedirect::query()->where('website_id', $website->id)->where('from_path', $from)->first() ?? new UrlRedirect(['website_id' => $website->id]);
        $before = $redirect->exists ? $redirect->only(['from_path', 'to_path', 'code', 'status']) : [];

        if ($redirect->exists && $redirect->from_path !== $from && UrlRedirect::query()->where('website_id', $website->id)->where('from_path', $from)->whereKeyNot($redirect->id)->exists()) {
            throw new DomainException('Bu eski adres için zaten bir yönlendirme var.');
        }

        $redirect->fill([
            'from_path' => $from, 'to_path' => $to, 'code' => $code, 'status' => $status, 'source' => $source,
            'score' => isset($data['score']) ? (int) $data['score'] : $redirect->score,
            'note' => isset($data['note']) ? mb_substr(trim((string) $data['note']), 0, 500) ?: null : $redirect->note,
        ]);

        if (! $redirect->exists) {
            $redirect->created_by = $actor?->id;
        }

        $redirect->save();

        // Kaynağa gelen etkin yönlendirmeler (X → from) doğrudan yeni hedefe (X → to): zincir oluşmaz.
        if ($status === 'active') {
            UrlRedirect::query()->where('website_id', $website->id)->where('to_path', $from)->whereKeyNot($redirect->id)->update(['to_path' => $to]);
            NotFoundLog::query()->where('website_id', $website->id)->where('path', $from)->update(['status' => 'redirected', 'suggested_path' => $to]);
        }

        $this->cache->invalidate($website);
        $this->audit->record($actor, $before === [] ? 'redirect.created' : 'redirect.updated', 'url_redirect', $redirect->id, $before, $redirect->only(['from_path', 'to_path', 'code', 'status', 'source']));

        return $redirect;
    }

    public function delete(Website $website, UrlRedirect $redirect, ?User $actor = null): void
    {
        if ((int) $redirect->website_id !== (int) $website->id) {
            throw new DomainException('Yönlendirme bu siteye ait değil.');
        }

        $redirect->delete();
        $this->cache->invalidate($website);
        $this->audit->record($actor, 'redirect.deleted', 'url_redirect', $redirect->id, $redirect->only(['from_path', 'to_path']), []);
    }

    /**
     * Etkin yönlendirme haritası (önbellekli): from => [to, code, id].
     *
     * @return array<string, array{to: string, code: int, id: int}>
     */
    public function map(Website $website): array
    {
        $map = $this->cache->remember($website, 'redirects', fn (): array => UrlRedirect::query()->where('website_id', $website->id)->active()
            ->get(['id', 'from_path', 'to_path', 'code'])
            ->mapWithKeys(fn (UrlRedirect $r) => [$r->from_path => ['to' => $r->to_path, 'code' => $r->code, 'id' => $r->id]])
            ->all());

        return is_array($map) ? $map : [];
    }

    /**
     * Yol için etkin yönlendirme (zinciri izler, döngüde durur). @return array{to: string, code: int, id: int}|null
     */
    public function resolve(Website $website, string $path): ?array
    {
        $map = $this->map($website);
        $path = $this->normalizePath($path);

        if (! isset($map[$path])) {
            return null;
        }

        $hit = $map[$path];
        $seen = [$path];

        for ($i = 0; $i < self::MAX_DEPTH && str_starts_with($hit['to'], '/') && isset($map[$hit['to']]) && ! in_array($hit['to'], $seen, true); $i++) {
            $seen[] = $hit['to'];
            $hit = ['to' => $map[$hit['to']]['to'], 'code' => $hit['code'], 'id' => $hit['id']];
        }

        return $hit;
    }

    /** İsabet sayacı (önbellek dışı, tek UPDATE). */
    public function registerHit(int $redirectId): void
    {
        UrlRedirect::query()->whereKey($redirectId)->update(['hits' => DB::raw('hits + 1'), 'last_hit_at' => Carbon::now()]);
    }

    /**
     * Zincirin sonu; döngüde null. @param  list<string>  $seen
     */
    public function finalTarget(Website $website, string $to, array $seen = []): ?string
    {
        $map = $this->map($website);

        for ($i = 0; $i < self::MAX_DEPTH; $i++) {
            if (in_array($to, $seen, true)) {
                return null;
            }

            if (! str_starts_with($to, '/') || ! isset($map[$to])) {
                return $to;
            }

            $seen[] = $to;
            $to = $map[$to]['to'];
        }

        return null;
    }

    public function normalizePath(string $path): string
    {
        $path = trim($path);
        $path = (string) parse_url($path, PHP_URL_PATH) ?: $path;
        $path = '/'.trim(mb_strtolower($path), '/');

        return $path === '//' ? '/' : $path;
    }

    /** Hedef: site içi yol ya da mutlak https adresi. */
    public function normalizeTarget(string $target): string
    {
        $target = trim($target);

        if (preg_match('~^https?://~i', $target) === 1) {
            return $target;
        }

        return $this->normalizePath($target);
    }
}

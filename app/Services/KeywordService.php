<?php

namespace App\Services;

use App\Models\Content;
use App\Models\EntityRelation;
use App\Models\Location;
use App\Models\SeoKeyword;
use App\Models\SeoLandingPage;
use App\Models\Service;
use App\Models\User;
use App\Models\Website;
use App\Seo\SchemaInspector;
use DomainException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;

/**
 * Keyword Intelligence (faz 60c): anahtar kelime → sayfa eşlemesi, arama niyeti, konu kümesi; deterministik
 * analizler — kanibalizasyon (aynı birincil kelime birden fazla sayfada), içerik boşluğu (hedefi olmayan/ölü
 * kelime), sayfa üstü kontrol (kelime başlık/açıklama/gövdede var mı), kümeler, eşleşmemiş sayfalar.
 * İçerik stüdyosundaki odak kelimeler (contents.focus_keyword) örtük eşleme olarak analize girer.
 * Hacim / sıralama / ilgili sorgu verisi burada ÜRETİLMEZ; yalnız bağlı Search Console'dan gelir.
 */
class KeywordService
{
    public function __construct(
        private readonly ContentService $contents,
        private readonly SchemaInspector $inspector,
        private readonly AuditService $audit,
    ) {}

    /** @return Collection<int, SeoKeyword> */
    public function all(Website $website): Collection
    {
        return SeoKeyword::query()->where('website_id', $website->id)->orderBy('cluster')->orderBy('role')->orderBy('normalized')->get();
    }

    /** @param  array<string, mixed>  $data */
    public function create(User $actor, Website $website, array $data): SeoKeyword
    {
        $keyword = trim((string) ($data['keyword'] ?? ''));

        if (mb_strlen($keyword) < 2) {
            throw new DomainException('Anahtar kelime en az 2 karakter olmalı.');
        }

        $normalized = SeoKeyword::normalize($keyword);
        $target = $this->resolveTarget($website, (string) ($data['target'] ?? ''));

        if (SeoKeyword::query()->where('website_id', $website->id)->where('normalized', $normalized)->where('target_path', $target['path'])->exists()) {
            throw new DomainException('Bu kelime aynı hedefe zaten bağlı.');
        }

        $row = SeoKeyword::query()->create([
            'website_id' => $website->id,
            'keyword' => mb_substr($keyword, 0, 120),
            'normalized' => mb_substr($normalized, 0, 120),
            'role' => isset(SeoKeyword::ROLES[$data['role'] ?? '']) ? $data['role'] : 'primary',
            'intent' => isset(SeoKeyword::INTENTS[$data['intent'] ?? '']) ? $data['intent'] : 'informational',
            'cluster' => trim((string) ($data['cluster'] ?? '')) !== '' ? Str::slug((string) $data['cluster']) : null,
            'target_type' => $target['type'],
            'target_id' => $target['id'],
            'target_path' => $target['path'],
            'note' => trim((string) ($data['note'] ?? '')) !== '' ? mb_substr(trim((string) $data['note']), 0, 300) : null,
            'created_by' => $actor->id,
        ]);
        $this->audit->record($actor, 'keyword.created', 'seo_keyword', $row->id, [], $row->toArray());

        return $row;
    }

    public function delete(User $actor, Website $website, int $id): void
    {
        $row = SeoKeyword::query()->where('website_id', $website->id)->find($id);

        if ($row === null) {
            throw new DomainException('Kayıt bulunamadı.');
        }

        $row->delete();
        $this->audit->record($actor, 'keyword.deleted', 'seo_keyword', $id, $row->toArray(), []);
    }

    /**
     * Hedef seçimi "type:id" ya da "/yol" biçimindedir; var olmayan hedef reddedilir.
     *
     * @return array{type: string|null, id: int|null, path: string|null}
     */
    public function resolveTarget(Website $website, string $target): array
    {
        $target = trim($target);

        if ($target === '') {
            return ['type' => null, 'id' => null, 'path' => null];
        }

        if (str_starts_with($target, '/')) {
            $path = '/'.trim($target, '/');

            return ['type' => 'path', 'id' => null, 'path' => $path === '/' ? '/' : $path];
        }

        [$type, $id] = array_pad(explode(':', $target, 2), 2, '');
        $id = (int) $id;
        $model = match ($type) {
            'content' => Content::query()->where('website_id', $website->id)->find($id),
            'service' => $website->is_default ? Service::query()->find($id) : null,
            'location' => $website->is_default ? Location::query()->find($id) : null,
            'landing' => SeoLandingPage::query()->where('website_id', $website->id)->with('service')->find($id),
            default => null,
        };

        if ($model === null) {
            throw new DomainException('Hedef bulunamadı.');
        }

        return ['type' => $type, 'id' => $id, 'path' => $model->path()];
    }

    /**
     * Seçilebilir hedefler (form): bilinen sayfalar.
     *
     * @return list<array{value: string, label: string}>
     */
    public function targetOptions(Website $website): array
    {
        $out = [];

        foreach ($this->contents->livePages($website)->merge($this->contents->livePosts($website, 1000)) as $c) {
            $out[] = ['value' => 'content:'.$c->id, 'label' => ($c->kind->value === 'post' ? 'Yazı: ' : 'Sayfa: ').$c->title.' ('.$c->path().')'];
        }

        if ($website->is_default) {
            foreach (Service::query()->active()->get() as $s) {
                $out[] = ['value' => 'service:'.$s->id, 'label' => 'Hizmet: '.$s->name.' ('.$s->path().')'];
            }
            foreach (Location::query()->published()->orderBy('name')->get() as $l) {
                $out[] = ['value' => 'location:'.$l->id, 'label' => 'Lokasyon: '.$l->name.' ('.$l->path().')'];
            }
            foreach (SeoLandingPage::query()->where('website_id', $website->id)->with('service')->get() as $p) {
                $out[] = ['value' => 'landing:'.$p->id, 'label' => 'Hizmet × şehir: '.$p->title.' ('.$p->path().')'];
            }
        }

        return $out;
    }

    /**
     * Analiz: deterministik, gerçek kayıtlardan.
     *
     * @return array<string, mixed>
     */
    public function analysis(Website $website): array
    {
        $keywords = $this->all($website);
        $pages = $this->inspector->pages($website);
        $known = [];

        foreach ($pages as $page) {
            $known[$page['path']] = $page;
        }

        $rows = [];
        $byNormalized = [];
        $mappedPaths = [];
        $clusters = [];

        foreach ($keywords as $k) {
            $onPage = $this->onPage($website, $k);
            $dead = $k->target_path !== null && ! isset($known[$k->target_path]);
            $rows[] = ['model' => $k, 'on_page' => $onPage, 'dead' => $dead, 'label' => $k->target_path !== null ? ($known[$k->target_path]['label'] ?? $k->target_path) : null];

            if ($k->role === 'primary' && $k->target_path !== null) {
                $byNormalized[$k->normalized][$k->target_path] = true;
            }

            if ($k->target_path !== null) {
                $mappedPaths[$k->target_path] = true;
            }

            if ($k->cluster !== null) {
                $clusters[$k->cluster]['keywords'][] = $k->keyword;
                $clusters[$k->cluster]['targets'][$k->target_path ?? '—'] = true;
            }
        }

        // Örtük eşleme: içerik stüdyosu odak kelimeleri.
        $implicit = [];

        foreach ($this->contents->livePages($website)->merge($this->contents->livePosts($website, 1000)) as $content) {
            $focus = trim((string) ($content->focus_keyword ?? ''));

            if ($focus !== '') {
                $implicit[] = ['keyword' => $focus, 'content' => $content];
                $mappedPaths[$content->path()] = true;
                $byNormalized[SeoKeyword::normalize($focus)][$content->path()] = true;
            }
        }

        $cannibalization = [];

        foreach ($byNormalized as $normalized => $paths) {
            if (count($paths) > 1) {
                $cannibalization[] = ['keyword' => $normalized, 'paths' => array_keys($paths)];
            }
        }

        $gaps = array_values(array_filter($rows, fn (array $r) => $r['model']->target_path === null || $r['dead']));
        $unmapped = array_values(array_filter($pages, fn (array $p) => ! isset($mappedPaths[$p['path']]) && ! in_array($p['kind'], ['static', 'listing', 'home'], true)));

        $topics = EntityRelation::query()->where('website_id', $website->id)->where('from_type', 'topic')->pluck('from_id')->unique()->all();

        foreach ($clusters as $slug => &$cluster) {
            $cluster['linked_topic'] = in_array($slug, $topics, true);
            $cluster['targets'] = array_keys($cluster['targets']);
        }
        unset($cluster);

        return [
            'rows' => $rows,
            'implicit' => $implicit,
            'cannibalization' => $cannibalization,
            'gaps' => $gaps,
            'clusters' => $clusters,
            'unmapped' => $unmapped,
            'counts' => ['total' => count($rows), 'primary' => $keywords->where('role', 'primary')->count(), 'clusters' => count($clusters), 'cannibal' => count($cannibalization), 'gaps' => count($gaps), 'unmapped' => count($unmapped)],
        ];
    }

    /**
     * Sayfa üstü kontrol: kelime hedefin başlığında / açıklamasında / gövdesinde geçiyor mu?
     *
     * @return array{title: bool, description: bool, body: int}|null
     */
    private function onPage(Website $website, SeoKeyword $k): ?array
    {
        $needle = $k->normalized;
        $count = fn (?string $text) => $needle === '' ? 0 : mb_substr_count(mb_strtolower((string) $text), $needle);

        $fields = match ($k->target_type) {
            'content' => (function () use ($website, $k) {
                $c = Content::query()->where('website_id', $website->id)->find($k->target_id);

                return $c === null ? null : ['title' => (string) ($c->meta_title ?: $c->title), 'description' => (string) ($c->meta_description ?: $c->excerpt), 'body' => (string) $c->body];
            })(),
            'service' => (function () use ($k) {
                $s = Service::query()->find($k->target_id);

                return $s === null ? null : ['title' => $s->name, 'description' => (string) $s->summary, 'body' => (string) $s->description.' '.json_encode($s->answers ?? [], JSON_UNESCAPED_UNICODE)];
            })(),
            'location' => (function () use ($k) {
                $l = Location::query()->find($k->target_id);

                return $l === null ? null : ['title' => $l->name.' '.$l->city, 'description' => (string) $l->geo_meta_description, 'body' => (string) $l->geo_description];
            })(),
            'landing' => (function () use ($k) {
                $p = SeoLandingPage::query()->find($k->target_id);

                return $p === null ? null : ['title' => $p->title, 'description' => (string) $p->meta_description, 'body' => $p->intro.' '.(string) $p->body];
            })(),
            default => null,
        };

        if ($fields === null) {
            return null;
        }

        return ['title' => $count($fields['title']) > 0, 'description' => $count($fields['description']) > 0, 'body' => $count($fields['body'])];
    }
}

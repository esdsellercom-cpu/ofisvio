<?php

namespace App\Services;

use App\Content\GeoSuggester;
use App\Content\PromptDefaults;
use App\Content\SeoAnalyzer;
use App\Enums\ContentStatus;
use App\Integrations\Ai\AiProviderInterface;
use App\Models\AiJob;
use App\Models\AiPrompt;
use App\Models\Content;
use App\Models\ContentRefreshCandidate;
use App\Models\Location;
use App\Models\SeoKeyword;
use App\Models\Service;
use App\Models\User;
use App\Models\Website;
use DomainException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Throwable;

/**
 * AI Content Engine (faz 60e): Konu keşfi → Brief → AI Draft → Fact Check → SEO → GEO → Duplicate Check →
 * Review → Approval → Schedule → Publish. Her aşama panelden insan tetiklemesiyle ilerler; AI çağrısı yalnız
 * Gateway üzerinden (sağlayıcı kapalıysa taslak aşaması durur, diğer denetimler deterministiktir).
 * Kayıt: sağlayıcı, model, prompt anahtarı+sürümü, token, maliyet (fiyat env'de tanımlıysa), aşama geçmişi.
 * AI yayındaki içeriği ASLA doğrudan değiştirmez: makale → yeni taslak Content; yenileme → çalışma taslağı.
 */
class AiContentService
{
    public function __construct(
        private readonly AiProviderInterface $ai,
        private readonly ContentService $contents,
        private readonly SeoSettingsService $settings,
        private readonly KeywordService $keywords,
        private readonly SearchPerformanceService $performance,
        private readonly AuditService $audit,
    ) {}

    // ---- Prompt Registry -----------------------------------------------------------------------

    /** Varsayılan prompt'lar yoksa sürüm 1 olarak yazılır. */
    public function ensurePrompts(): void
    {
        foreach (PromptDefaults::all() as $key => $def) {
            if (! AiPrompt::query()->where('key', $key)->exists()) {
                AiPrompt::query()->create(['key' => $key, 'version' => 1, 'name' => $def['name'], 'system' => $def['system'], 'template' => $def['template'], 'is_active' => true]);
            }
        }
    }

    /** @return Collection<int, AiPrompt> */
    public function prompts(): Collection
    {
        $this->ensurePrompts();

        return AiPrompt::query()->orderBy('key')->orderByDesc('version')->get();
    }

    /** Yeni sürüm: eski sürüm pasifleşir, yeni sürüm aktif olur (iş kayıtları eski sürümü referans olarak tutar). */
    public function newPromptVersion(User $actor, string $key, string $name, string $system, string $template, ?string $model): AiPrompt
    {
        if (! isset(AiPrompt::KEYS[$key])) {
            throw new DomainException('Bilinmeyen prompt anahtarı.');
        }

        if (trim($template) === '' || trim($system) === '') {
            throw new DomainException('Sistem metni ve şablon boş olamaz.');
        }

        $version = (int) AiPrompt::query()->where('key', $key)->max('version') + 1;
        AiPrompt::query()->where('key', $key)->update(['is_active' => false]);
        $prompt = AiPrompt::query()->create(['key' => $key, 'version' => $version, 'name' => mb_substr(trim($name) ?: AiPrompt::KEYS[$key], 0, 120), 'system' => trim($system), 'template' => trim($template), 'model' => trim((string) $model) ?: null, 'is_active' => true, 'created_by' => $actor->id]);
        $this->audit->record($actor, 'ai.prompt_versioned', 'ai_prompt', $prompt->id, [], ['key' => $key, 'version' => $version]);

        return $prompt;
    }

    public function activePrompt(string $key): AiPrompt
    {
        $this->ensurePrompts();
        $prompt = AiPrompt::query()->where('key', $key)->where('is_active', true)->orderByDesc('version')->first();

        if ($prompt === null) {
            throw new DomainException('Aktif prompt yok: '.$key);
        }

        return $prompt;
    }

    // ---- Konu keşfi --------------------------------------------------------------------------------

    /**
     * Konu adayları: hedefsiz anahtar kelimeler, Search Console fırsat sorguları (varsa), yenileme adayları.
     *
     * @return list<array{topic: string, source: string, detail: string, keyword: string|null, content_id: int|null}>
     */
    public function discoverTopics(Website $website): array
    {
        $out = [];

        foreach ($this->keywords->analysis($website)['gaps'] as $gap) {
            $k = $gap['model'];

            if ($k->target_path === null) {
                $out[] = ['topic' => $k->keyword, 'source' => 'Keyword Intelligence', 'detail' => 'Hedef sayfası olmayan '.SeoKeyword::ROLES[$k->role].' kelime ('.SeoKeyword::INTENTS[$k->intent].')', 'keyword' => $k->keyword, 'content_id' => null];
            }
        }

        $summary = $this->performance->searchSummary($website);

        foreach ((array) ($summary['opportunities'] ?? []) as $q) {
            $out[] = ['topic' => (string) $q->key, 'source' => 'Search Console', 'detail' => $q->impressions.' gösterim · sıra '.$q->position.' — 2. sayfa fırsatı', 'keyword' => (string) $q->key, 'content_id' => null];
        }

        foreach (ContentRefreshCandidate::query()->where('website_id', $website->id)->where('status', 'open')->with('content')->orderByDesc('score')->limit(20)->get() as $candidate) {
            if ($candidate->content !== null) {
                $out[] = ['topic' => $candidate->content->title, 'source' => 'İçerik yenileme', 'detail' => implode(' · ', array_map(fn ($r) => is_array($r) ? (string) ($r['label'] ?? '') : (string) $r, (array) $candidate->reasons)), 'keyword' => $candidate->content->focus_keyword, 'content_id' => $candidate->content->id];
            }
        }

        return $out;
    }

    // ---- İş hattı ----------------------------------------------------------------------------------

    /**
     * Brief: insan girdisi (konu, kitle, niyet, anahtar kelimeler, ilgili hizmet/lokasyon, notlar). Yenileme işi kaynak içeriğe bağlanır.
     *
     * @param  array<string, mixed>  $brief
     */
    public function createJob(User $actor, Website $website, string $topic, array $brief, ?Content $source = null): AiJob
    {
        $topic = trim($topic);

        if (mb_strlen($topic) < 3) {
            throw new DomainException('Konu en az 3 karakter olmalı.');
        }

        if ($source !== null && $source->website_id !== $website->id) {
            throw new DomainException('Kaynak içerik bu siteye ait değil.');
        }

        $job = AiJob::query()->create([
            'website_id' => $website->id,
            'kind' => $source !== null ? 'refresh' : 'article',
            'stage' => 'draft',
            'topic' => mb_substr($topic, 0, 200),
            'brief' => [
                'audience' => mb_substr(trim((string) ($brief['audience'] ?? '')), 0, 300),
                'intent' => mb_substr(trim((string) ($brief['intent'] ?? '')), 0, 60),
                'keywords' => mb_substr(trim((string) ($brief['keywords'] ?? '')), 0, 300),
                'services' => array_values(array_map('intval', array_filter((array) ($brief['services'] ?? []), 'is_numeric'))),
                'locations' => array_values(array_map('intval', array_filter((array) ($brief['locations'] ?? []), 'is_numeric'))),
                'notes' => mb_substr(trim((string) ($brief['notes'] ?? '')), 0, 2000),
            ],
            'source_content_id' => $source?->id,
            'history' => [self::event('brief', $actor, 'Brief oluşturuldu')],
            'created_by' => $actor->id,
        ]);
        $this->audit->record($actor, 'ai.job_created', 'ai_job', $job->id, [], ['topic' => $job->topic, 'kind' => $job->kind]);

        return $job;
    }

    /** AI taslak: sağlayıcı bağlı değilse durur (fail-closed); yanıt JSON ayrıştırılır. */
    public function runDraft(User $actor, AiJob $job): AiJob
    {
        $this->assertStage($job, 'draft');

        if (! $this->ai->available()) {
            throw new DomainException('AI sağlayıcısı bağlı değil (AI_ENABLED + AI_API_KEY); taslak üretilemez.');
        }

        $website = $job->website;
        $prompt = $this->activePrompt($job->kind === 'refresh' ? 'refresh' : 'draft');
        $vars = $this->variables($website, $job);
        $user = strtr($prompt->template, $vars);

        try {
            $result = $this->ai->complete($prompt->system, $user, $prompt->model, 6000);
        } catch (Throwable $e) {
            $job->error = mb_substr($e->getMessage(), 0, 500);
            $job->save();

            throw new DomainException('AI çağrısı başarısız: '.mb_substr($e->getMessage(), 0, 200));
        }

        $draft = self::parseDraft($result['text']);

        if ($draft === null) {
            $job->error = 'Yanıt JSON değil.';
            $job->save();

            throw new DomainException('AI yanıtı ayrıştırılamadı (JSON bekleniyordu); yeniden deneyin ya da prompt sürümünü gözden geçirin.');
        }

        $job->fill([
            'draft' => $draft,
            'provider' => $result['provider'],
            'model' => $result['model'],
            'prompt_key' => $prompt->key,
            'prompt_version' => $prompt->version,
            'input_tokens' => $job->input_tokens + $result['input_tokens'],
            'output_tokens' => $job->output_tokens + $result['output_tokens'],
            'error' => null,
        ]);
        $this->applyCost($job);
        $this->advance($job, 'fact_check', $actor, 'AI taslak üretildi ('.$result['model'].', '.$result['input_tokens'].'+'.$result['output_tokens'].' token)');
        $this->audit->record($actor, 'ai.draft_generated', 'ai_job', $job->id, [], ['model' => $result['model'], 'prompt' => $prompt->key.'@'.$prompt->version, 'tokens' => [$result['input_tokens'], $result['output_tokens']]]);

        return $job;
    }

    /**
     * Doğruluk kontrolü: deterministik iddia çıkarımı (rakam, yüzde, para, telefon, yıl, mutlak ifadeler) + varsa
     * AI iddia listesi. İnsan bu listeyi inceleme aşamasında doğrular; yüksek riskli iddia yayın öncesi işaretlenir.
     */
    public function runFactCheck(User $actor, AiJob $job): AiJob
    {
        $this->assertStage($job, 'fact_check');
        $body = (string) ($job->draft['body'] ?? '');
        $claims = [];

        foreach (['/\b\d{1,3}(\.\d{3})+(,\d+)?\b|\b\d+([.,]\d+)?\s?(%|₺|TL|USD|EUR)/u' => ['rakam/para', 'high'], '/\b(en (ucuz|iyi|hızlı|büyük)|tek|ilk|garanti|kesin|her zaman)\b/iu' => ['mutlak ifade', 'medium'], '/\b(19|20)\d{2}\b/u' => ['yıl', 'medium'], '/\+?9?0?\s?\(?5\d{2}\)?\s?\d{3}\s?\d{2}\s?\d{2}/u' => ['telefon', 'high'], '/\b(kanun|yönetmelik|tebliğ|madde \d+)\b/iu' => ['yasal atıf', 'medium']] as $pattern => [$type, $risk]) {
            if (preg_match_all($pattern, $body, $m, PREG_OFFSET_CAPTURE) > 0) {
                foreach (array_slice($m[0], 0, 6) as [$match, $offset]) {
                    $claims[] = ['claim' => trim(mb_substr($body, max(0, $offset - 40), mb_strlen($match) + 80)), 'type' => $type, 'risk' => $risk, 'source' => 'kural'];
                }
            }
        }

        if ($this->ai->available()) {
            try {
                $prompt = $this->activePrompt('fact_check');
                $result = $this->ai->complete($prompt->system, strtr($prompt->template, ['{body}' => mb_substr($body, 0, 12000)]), $prompt->model, 2000);
                $json = self::json($result['text']);

                foreach ((array) ($json['claims'] ?? []) as $c) {
                    if (is_array($c) && trim((string) ($c['claim'] ?? '')) !== '') {
                        $claims[] = ['claim' => mb_substr((string) $c['claim'], 0, 300), 'type' => mb_substr((string) ($c['type'] ?? 'iddia'), 0, 40), 'risk' => in_array($c['risk'] ?? '', ['low', 'medium', 'high'], true) ? $c['risk'] : 'medium', 'source' => 'AI', 'why' => mb_substr((string) ($c['why'] ?? ''), 0, 200)];
                    }
                }

                $job->input_tokens += $result['input_tokens'];
                $job->output_tokens += $result['output_tokens'];
                $this->applyCost($job);
            } catch (Throwable $e) {
                $claims[] = ['claim' => 'AI iddia çıkarımı yapılamadı: '.mb_substr($e->getMessage(), 0, 120), 'type' => 'sistem', 'risk' => 'low', 'source' => 'AI'];
            }
        }

        $checks = $job->checks ?? [];
        $checks['fact'] = ['claims' => $claims, 'high' => count(array_filter($claims, fn (array $c) => $c['risk'] === 'high')), 'checked_at' => now()->toIso8601String()];
        $job->checks = $checks;
        $this->advance($job, 'seo', $actor, count($claims).' iddia listelendi');

        return $job;
    }

    /** SEO: içerik stüdyosunun deterministik analizi (SeoAnalyzer) aynı kurallarla. */
    public function runSeo(User $actor, AiJob $job): AiJob
    {
        $this->assertStage($job, 'seo');
        $d = $job->draft ?? [];
        $analysis = SeoAnalyzer::analyze([
            'title' => (string) ($d['title'] ?? ''), 'slug' => Str::slug((string) ($d['title'] ?? '')), 'excerpt' => (string) ($d['excerpt'] ?? ''), 'body' => (string) ($d['body'] ?? ''),
            'meta_title' => (string) ($d['meta_title'] ?? ''), 'meta_description' => (string) ($d['meta_description'] ?? ''), 'focus_keyword' => trim(explode(',', (string) ($job->brief['keywords'] ?? ''))[0]),
            'canonical_url' => '', 'schema_types' => [], 'cover' => null,
        ]);
        $checks = $job->checks ?? [];
        $checks['seo'] = $analysis;
        $job->checks = $checks;
        $this->advance($job, 'geo', $actor, 'SEO skoru '.$analysis['score']);

        return $job;
    }

    /** GEO: GeoSuggester (deterministik) — yapılandırılmış cevap önerileri; varlık adları hizmet/lokasyondan. */
    public function runGeo(User $actor, AiJob $job): AiJob
    {
        $this->assertStage($job, 'geo');
        $website = $job->website;
        $d = $job->draft ?? [];
        $entities = array_merge(Service::query()->active()->pluck('name')->all(), Location::query()->published()->pluck('name')->all());
        $others = $this->contents->livePosts($website, 1000);
        $suggestions = GeoSuggester::suggest(['title' => (string) ($d['title'] ?? ''), 'excerpt' => (string) ($d['excerpt'] ?? ''), 'body' => (string) ($d['body'] ?? ''), 'focus_keyword' => trim(explode(',', (string) ($job->brief['keywords'] ?? ''))[0]), 'kind' => 'post'], $others, $entities);
        $faq = count((array) ($d['faq'] ?? []));
        $mentions = array_values(array_filter($entities, fn (string $name) => str_contains(mb_strtolower((string) ($d['body'] ?? '')), mb_strtolower($name))));
        $checks = $job->checks ?? [];
        $checks['geo'] = ['suggestions' => $suggestions, 'faq_count' => $faq, 'entities_mentioned' => $mentions, 'ok' => $faq >= 3 && $mentions !== []];
        $job->checks = $checks;
        $this->advance($job, 'duplicate', $actor, 'GEO: '.$faq.' SSS · '.count($mentions).' varlık geçişi');

        return $job;
    }

    /** Kopya denetimi: yayındaki içeriklerle 5 kelimelik parça benzerliği; ≥ %60 engel, ≥ %35 uyarı. */
    public function runDuplicate(User $actor, AiJob $job): AiJob
    {
        $this->assertStage($job, 'duplicate');
        $website = $job->website;
        $own = self::shingles((string) ($job->draft['body'] ?? ''));
        $worst = ['ratio' => 0.0, 'path' => null, 'title' => null];

        foreach ($this->contents->livePosts($website, 1000)->merge($this->contents->livePages($website)) as $content) {
            if ($job->source_content_id !== null && $content->id === $job->source_content_id) {
                continue; // yenilemede kaynağın kendisiyle benzerlik doğaldır
            }

            $ratio = self::jaccard($own, self::shingles((string) $content->body));

            if ($ratio > $worst['ratio']) {
                $worst = ['ratio' => $ratio, 'path' => $content->path(), 'title' => $content->title];
            }
        }

        $checks = $job->checks ?? [];
        $checks['duplicate'] = ['ratio' => round($worst['ratio'], 3), 'path' => $worst['path'], 'title' => $worst['title'], 'blocked' => $worst['ratio'] >= 0.6, 'warn' => $worst['ratio'] >= 0.35];
        $job->checks = $checks;

        if ($worst['ratio'] >= 0.6) {
            $job->stage = 'draft';
            $job->history = array_merge($job->history ?? [], [self::event('duplicate', $actor, 'Kopya: %'.(int) round($worst['ratio'] * 100).' ('.$worst['title'].') — taslak aşamasına geri döndü')]);
            $job->save();

            throw new DomainException('Taslak yayındaki "'.$worst['title'].'" ile %'.(int) round($worst['ratio'] * 100).' benziyor; yeniden üretin ya da brief\'i değiştirin.');
        }

        $this->advance($job, 'review', $actor, 'En yüksek benzerlik %'.(int) round($worst['ratio'] * 100));

        return $job;
    }

    /**
     * İnceleme (ai_content.review): insan taslağı düzenleyebilir (title/excerpt/body/meta), not bırakır.
     *
     * @param  array<string, mixed>  $edits
     */
    public function review(User $actor, AiJob $job, array $edits, ?string $note, bool $reject = false): AiJob
    {
        $this->assertStage($job, 'review');

        if ($reject) {
            $job->stage = 'rejected';
            $job->reviewed_by = $actor->id;
            $job->history = array_merge($job->history ?? [], [self::event('rejected', $actor, $note ?: 'Reddedildi')]);
            $job->save();
            $this->audit->record($actor, 'ai.job_rejected', 'ai_job', $job->id, [], ['note' => $note]);

            return $job;
        }

        $draft = $job->draft ?? [];

        foreach (['title', 'excerpt', 'body', 'meta_title', 'meta_description'] as $field) {
            if (array_key_exists($field, $edits) && is_string($edits[$field])) {
                $draft[$field] = trim($edits[$field]);
            }
        }

        $job->draft = $draft;
        $job->reviewed_by = $actor->id;
        $this->advance($job, 'approval', $actor, $note ?: 'İncelendi');

        return $job;
    }

    /** Onay (ai_content.approve): dört göz — inceleyenden farklı kullanıcı; yüksek riskli iddia varsa açıkça kabul edilir. */
    public function approve(User $actor, AiJob $job, bool $acceptRisks = false): AiJob
    {
        $this->assertStage($job, 'approval');

        if ($job->reviewed_by === $actor->id) {
            throw new DomainException('Onay, inceleyenden farklı bir kullanıcı tarafından verilmeli (dört göz).');
        }

        if ((int) ($job->checks['fact']['high'] ?? 0) > 0 && ! $acceptRisks) {
            throw new DomainException('Yüksek riskli iddialar var; doğruladığınızı işaretlemeden onaylanamaz.');
        }

        $job->approved_by = $actor->id;
        $this->advance($job, 'schedule', $actor, 'Onaylandı'.($acceptRisks ? ' (iddialar doğrulandı)' : ''));
        $this->audit->record($actor, 'ai.job_approved', 'ai_job', $job->id, [], ['accept_risks' => $acceptRisks]);

        return $job;
    }

    /** Zamanlama: tarih verilirse zamanlanmış, verilmezse hemen yayın aşamasına. */
    public function schedule(User $actor, AiJob $job, ?Carbon $when): AiJob
    {
        $this->assertStage($job, 'schedule');

        if ($when !== null && $when->isPast()) {
            throw new DomainException('Zamanlama gelecekte olmalı.');
        }

        $job->scheduled_for = $when;
        $this->advance($job, 'publish', $actor, $when !== null ? 'Zamanlandı: '.$when->format('d.m.Y H:i') : 'Hemen yayın');

        return $job;
    }

    /**
     * Yayın (ai_content.publish): makale → CMS'de yeni içerik (taslak ya da zamanlanmış; yayın CMS akışıyla —
     * yayınlama izni varsa hemen). Yenileme → yayındaki içeriğin ÇALIŞMA TASLAĞI (yayındaki metin değişmez).
     */
    public function publish(User $actor, AiJob $job, bool $publishNow): AiJob
    {
        $this->assertStage($job, 'publish');
        $website = $job->website;
        $d = $job->draft ?? [];
        $body = (string) ($d['body'] ?? '');

        if (trim($body) === '' || trim((string) ($d['title'] ?? '')) === '') {
            throw new DomainException('Taslak boş.');
        }

        $data = [
            'title' => (string) $d['title'], 'excerpt' => (string) ($d['excerpt'] ?? ''), 'body' => $body,
            'meta_title' => (string) ($d['meta_title'] ?? ''), 'meta_description' => (string) ($d['meta_description'] ?? ''),
            'tags' => implode(', ', array_map('strval', (array) ($d['tags'] ?? []))), 'focus_keyword' => trim(explode(',', (string) ($job->brief['keywords'] ?? ''))[0]) ?: null,
        ];

        if ($job->kind === 'refresh' && $job->source !== null) {
            $source = $job->source;
            // Taze örnekler: create/openDraft DB varsayılan durumunu bellekte taşımaz (status null → refresh).
            $draft = $source->status === ContentStatus::PUBLISHED ? $this->contents->openDraft($actor, $source)->refresh() : null;

            if ($draft === null) {
                $content = $this->contents->update($actor, $source, $data + ['kind' => $source->kind->value, 'category' => $source->category]);
            } else {
                $this->contents->updateDraft($actor, $draft, $data + ['category' => $source->category]);
                $content = $source;
            }
        } else {
            $content = $this->contents->create($actor, $website, $data + ['kind' => 'post', 'category' => (string) ($job->brief['intent'] ?? '') === 'local' ? 'Yerel' : null])->refresh();

            if ($job->scheduled_for !== null) {
                // CMS durum makinesi: taslak → incelemede → zamanlanmış (inceleme/onay AI hattında insan tarafından yapıldı).
                $content = $this->contents->transition($actor, $content, ContentStatus::IN_REVIEW, 'AI içerik hattı: incelendi ve onaylandı');
                $this->contents->transition($actor, $content, ContentStatus::SCHEDULED, 'AI içerik hattı', Carbon::instance($job->scheduled_for));
            } elseif ($publishNow) {
                $this->contents->publishNow($actor, $content);
            }
        }

        $job->content_id = $content->id;
        $this->advance($job, 'done', $actor, $job->kind === 'refresh' ? 'Çalışma taslağı oluşturuldu: '.$content->title : ('İçerik oluşturuldu: '.$content->title.($publishNow && $job->scheduled_for === null ? ' (yayınlandı)' : '')));
        ContentRefreshCandidate::query()->where('ai_job_id', $job->id)->update(['status' => 'done']);
        $this->audit->record($actor, 'ai.job_published', 'ai_job', $job->id, [], ['content_id' => $content->id, 'kind' => $job->kind]);

        return $job;
    }

    /** @return Collection<int, AiJob> */
    public function jobs(Website $website): Collection
    {
        return AiJob::query()->where('website_id', $website->id)->with(['content', 'source', 'creator'])->orderByDesc('id')->get();
    }

    public function find(Website $website, int $id): ?AiJob
    {
        return AiJob::query()->where('website_id', $website->id)->with(['content', 'source'])->find($id);
    }

    /**
     * Kullanım & maliyet özeti: model bazında token/maliyet, iş sayıları; fiyat tanımlı değilse yalnız token.
     *
     * @return array<string, mixed>
     */
    public function usage(Website $website): array
    {
        $jobs = AiJob::query()->where('website_id', $website->id)->get();
        $byModel = [];

        foreach ($jobs as $job) {
            $model = $job->model ?? '—';
            $byModel[$model] ??= ['jobs' => 0, 'input' => 0, 'output' => 0, 'cost' => 0.0];
            $byModel[$model]['jobs']++;
            $byModel[$model]['input'] += $job->input_tokens;
            $byModel[$model]['output'] += $job->output_tokens;
            $byModel[$model]['cost'] += (float) ($job->cost ?? 0);
        }

        return [
            'by_model' => $byModel,
            'total' => ['jobs' => $jobs->count(), 'input' => (int) $jobs->sum('input_tokens'), 'output' => (int) $jobs->sum('output_tokens'), 'cost' => (float) $jobs->sum('cost')],
            'priced' => config('integrations.providers.ai.price_input_per_mtok') !== null && config('integrations.providers.ai.price_output_per_mtok') !== null,
            'currency' => (string) config('integrations.providers.ai.price_currency', 'USD'),
            'stages' => $jobs->countBy('stage')->all(),
        ];
    }

    // ---- yardımcılar ------------------------------------------------------------------------------

    private function assertStage(AiJob $job, string $stage): void
    {
        if ($job->stage !== $stage) {
            throw new DomainException('Bu iş "'.(AiJob::STAGES[$job->stage] ?? $job->stage).'" aşamasında; "'.AiJob::STAGES[$stage].'" adımı uygulanamaz.');
        }
    }

    private function advance(AiJob $job, string $to, User $actor, string $note): void
    {
        $job->stage = $to;
        $job->history = array_merge($job->history ?? [], [self::event($to, $actor, $note)]);
        $job->save();
    }

    /** @return array{stage: string, at: string, by: int, name: string, note: string} */
    private static function event(string $stage, User $actor, string $note): array
    {
        return ['stage' => $stage, 'at' => now()->toIso8601String(), 'by' => $actor->id, 'name' => $actor->name, 'note' => mb_substr($note, 0, 300)];
    }

    /** Maliyet yalnız fiyat env'de tanımlıysa (1M token başına) yazılır. */
    private function applyCost(AiJob $job): void
    {
        $in = config('integrations.providers.ai.price_input_per_mtok');
        $out = config('integrations.providers.ai.price_output_per_mtok');

        if ($in === null || $out === null || ! is_numeric($in) || ! is_numeric($out)) {
            return;
        }

        $job->cost = round($job->input_tokens / 1_000_000 * (float) $in + $job->output_tokens / 1_000_000 * (float) $out, 4);
        $job->cost_currency = (string) config('integrations.providers.ai.price_currency', 'USD');
    }

    /**
     * Prompt yer tutucuları — yalnız DB'deki gerçek bilgi.
     *
     * @return array<string, string>
     */
    private function variables(Website $website, AiJob $job): array
    {
        $brief = $job->brief ?? [];
        $serviceIds = (array) ($brief['services'] ?? []);
        $services = Service::query()->active()->when($serviceIds !== [], fn ($q) => $q->whereIn('id', $serviceIds))->get();
        $locations = Location::query()->published()->when(($brief['locations'] ?? []) !== [], fn ($q) => $q->whereIn('id', (array) $brief['locations']))->get();
        $s = $this->settings->for($website);
        $brand = trim(implode(' ', array_filter([$website->name, trim((string) $s['geo.brand_definition']), trim((string) $s['geo.summary_short'])])));

        return [
            '{topic}' => $job->topic,
            '{brief}' => trim(implode("\n", ['Kitle: '.($brief['audience'] ?? ''), 'Niyet: '.($brief['intent'] ?? ''), 'Notlar: '.($brief['notes'] ?? '')])),
            '{keywords}' => (string) ($brief['keywords'] ?? ''),
            '{brand}' => $brand !== '' ? $brand : $website->name,
            '{services}' => $services->map(fn (Service $svc) => '- '.$svc->name.': '.trim((string) ($svc->summary ?? '')).(is_array($svc->answers) && trim((string) ($svc->answers['what'] ?? '')) !== '' ? ' '.trim((string) $svc->answers['what']) : ''))->implode("\n") ?: '- (hizmet seçilmedi)',
            '{locations}' => $locations->map(fn (Location $l) => '- '.$l->name.' ('.$l->city.($l->district ? ', '.$l->district : '').')')->implode("\n") ?: '- (lokasyon seçilmedi)',
            '{body}' => $job->source !== null ? (string) $job->source->body : '',
            '{title}' => $job->source !== null ? $job->source->title : $job->topic,
            '{city}' => (string) ($locations->first()->city ?? ''),
        ];
    }

    /** @return array<string, mixed>|null */
    private static function parseDraft(string $text): ?array
    {
        $json = self::json($text);

        if ($json === null || trim((string) ($json['title'] ?? '')) === '' || trim((string) ($json['body'] ?? '')) === '') {
            return null;
        }

        $faq = [];

        foreach ((array) ($json['faq'] ?? []) as $row) {
            if (is_array($row) && trim((string) ($row['q'] ?? '')) !== '' && trim((string) ($row['a'] ?? '')) !== '') {
                $faq[] = ['q' => mb_substr(trim((string) $row['q']), 0, 200), 'a' => mb_substr(trim((string) $row['a']), 0, 1000)];
            }
        }

        return [
            'title' => mb_substr(trim((string) $json['title']), 0, 200),
            'excerpt' => mb_substr(trim((string) ($json['excerpt'] ?? '')), 0, 300),
            'body' => trim((string) $json['body']),
            'meta_title' => mb_substr(trim((string) ($json['meta_title'] ?? '')), 0, 70),
            'meta_description' => mb_substr(trim((string) ($json['meta_description'] ?? '')), 0, 170),
            'faq' => $faq,
            'tags' => array_slice(array_values(array_filter(array_map(fn ($t) => mb_strtolower(trim((string) $t)), (array) ($json['tags'] ?? [])), fn (string $t) => $t !== '')), 0, 5),
            'change_summary' => array_values(array_map('strval', (array) ($json['change_summary'] ?? []))),
        ];
    }

    /** Yanıt içindeki ilk JSON nesnesi (kod çiti ya da açıklama metni olsa da). @return array<string, mixed>|null */
    private static function json(string $text): ?array
    {
        $text = trim($text);
        $decoded = json_decode($text, true);

        if (is_array($decoded)) {
            return $decoded;
        }

        if (preg_match('/\{.*\}/s', $text, $m) === 1) {
            $decoded = json_decode($m[0], true);

            return is_array($decoded) ? $decoded : null;
        }

        return null;
    }

    /** @return array<string, true> */
    private static function shingles(string $text): array
    {
        $words = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower(strip_tags($text))) ?: [];
        $words = array_values(array_filter($words, fn (string $w) => $w !== ''));
        $set = [];

        for ($k = 0; $k + 5 <= count($words); $k++) {
            $set[implode(' ', array_slice($words, $k, 5))] = true;
        }

        return $set;
    }

    /**
     * @param  array<string, true>  $a
     * @param  array<string, true>  $b
     */
    private static function jaccard(array $a, array $b): float
    {
        if ($a === [] || $b === []) {
            return 0.0;
        }

        $intersection = count(array_intersect_key($a, $b));

        return $intersection / (count($a) + count($b) - $intersection);
    }
}

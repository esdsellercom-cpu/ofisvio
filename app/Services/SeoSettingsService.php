<?php

namespace App\Services;

use App\Models\User;
use App\Models\Website;
use App\Seo\SeoSettingsRegistry;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * SEO & GEO gelişmiş ayarları (faz 44): okuma = registry varsayılanı + websites.seo_settings JSON;
 * yazma = sekme bazlı doğrulama/normalizasyon, audit (seo.settings_updated), site önbelleği sürüm atlar.
 *
 * Değer JSON'da yalnız açıkça kaydedilmiş anahtarlar için tutulur; varsayılana eşit değer de yazılır
 * (kullanıcı "kapalı" dediyse ileride varsayılan değişse de kapalı kalır).
 */
class SeoSettingsService
{
    public function __construct(private readonly AuditService $audit, private readonly ContentCache $cache) {}

    /**
     * Etkin ayarlar (varsayılanla birleşik).
     *
     * @return array<string, mixed>
     */
    public function for(Website $website): array
    {
        $stored = is_array($website->seo_settings) ? $website->seo_settings : [];
        $out = [];

        foreach (SeoSettingsRegistry::definitions() as $key => $def) {
            $out[$key] = array_key_exists($key, $stored) ? $this->cast($def, $stored[$key]) : $def['default'];
        }

        return $out;
    }

    public function get(Website $website, string $key): mixed
    {
        $def = SeoSettingsRegistry::definition($key);
        $stored = is_array($website->seo_settings) ? $website->seo_settings : [];

        return array_key_exists($key, $stored) ? $this->cast($def, $stored[$key]) : $def['default'];
    }

    public function bool(Website $website, string $key): bool
    {
        return (bool) $this->get($website, $key);
    }

    public function string(Website $website, string $key): string
    {
        return trim((string) $this->get($website, $key));
    }

    /** @return array<int, string> */
    public function lines(Website $website, string $key): array
    {
        return array_values(array_filter(array_map('strval', (array) $this->get($website, $key)), fn (string $v) => $v !== ''));
    }

    /** @return array<int, array<string, string>> */
    public function rows(Website $website, string $key): array
    {
        return array_values(array_filter((array) $this->get($website, $key), 'is_array'));
    }

    /**
     * Sekme formu kaydı. Girdi ham form dizisidir (anahtarlar '.' yerine '__' ile gelir).
     * Doğrulama hatası ValidationException (formda alan bazında gösterilir).
     *
     * @param  array<string, mixed>  $input
     */
    public function updateTab(User $actor, Website $website, string $tab, array $input): Website
    {
        $defs = SeoSettingsRegistry::forTab($tab);
        $rules = [];
        $attributes = [];
        $data = [];

        foreach ($defs as $key => $def) {
            $field = self::field($key);
            $attributes[$field] = $def['label'];
            $raw = $input[$field] ?? null;

            switch ($def['type']) {
                case 'bool':
                    $data[$field] = (bool) $raw;
                    break;
                case 'multi':
                    $data[$field] = array_values(array_intersect(array_keys($def['options'] ?? []), array_map('strval', (array) $raw)));
                    break;
                case 'lines':
                    $data[$field] = is_string($raw) ? trim($raw) : '';
                    $rules[$field] = ['nullable', ...$def['rules']];
                    break;
                case 'rows':
                    $data[$field] = $this->normalizeRows($def, $raw);
                    $rules[$field] = ['nullable', ...$def['rules']];

                    foreach ($def['columns'] ?? [] as $column => $colDef) {
                        $rules["{$field}.*.{$column}"] = $colDef['rules'];
                        $attributes["{$field}.*.{$column}"] = $def['label'].' › '.$colDef['label'];
                    }
                    break;
                case 'int':
                    $data[$field] = is_numeric($raw) ? (int) $raw : $def['default'];
                    $rules[$field] = $def['rules'];
                    break;
                default:
                    $data[$field] = is_string($raw) ? trim($raw) : '';
                    $rules[$field] = ['nullable', ...$def['rules']];
            }
        }

        $validator = Validator::make($data, $rules, [], $attributes);
        $validator->after(function ($v) use ($defs, $data) {
            foreach ($defs as $key => $def) {
                $field = self::field($key);

                if ($def['type'] === 'json' && $data[$field] !== '' && ! self::validJsonLd($data[$field])) {
                    $v->errors()->add($field, $def['label'].': geçerli bir JSON nesnesi ya da dizisi olmalı.');
                }

                if ($def['type'] === 'rows') {
                    foreach ($def['columns'] ?? [] as $column => $colDef) {
                        if ($colDef['type'] !== 'json') {
                            continue;
                        }

                        foreach ($data[$field] as $i => $row) {
                            if (($row[$column] ?? '') !== '' && ! self::validJsonLd($row[$column])) {
                                $v->errors()->add("{$field}.{$i}.{$column}", $def['label'].' › '.$colDef['label'].': geçerli JSON değil.');
                            }
                        }
                    }
                }

                if ($key === 'crawl.sitemap_custom' && $data[$field] !== '' && ! str_starts_with(ltrim($data[$field]), '<?xml')) {
                    $v->errors()->add($field, 'Özel sitemap <?xml ile başlamalı.');
                }
            }
        });

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        $stored = is_array($website->seo_settings) ? $website->seo_settings : [];
        $before = array_intersect_key($stored, $defs);

        foreach ($defs as $key => $def) {
            $value = $data[self::field($key)];
            $stored[$key] = match ($def['type']) {
                'lines' => self::splitLines((string) $value),
                'json', 'text', 'string', 'url', 'date' => (string) $value,
                default => $value,
            };
        }

        // IndexNow anahtarı: açıkken boş bırakıldıysa üretilir (32 hex).
        if ($tab === 'dogrulama' && ($stored['indexing.indexnow_enabled'] ?? false) && ($stored['indexing.indexnow_key'] ?? '') === '') {
            $stored['indexing.indexnow_key'] = bin2hex(random_bytes(16));
        }

        $website->forceFill(['seo_settings' => $stored])->save();
        $this->cache->invalidate($website);
        $this->audit->record($actor, 'seo.settings_updated', 'website', $website->id, ['tab' => $tab, 'values' => $before], ['tab' => $tab, 'values' => array_intersect_key($stored, $defs)]);

        return $website;
    }

    /**
     * Tekil ayar yazımı (faz 60, Command Center otomatik düzeltmeleri): yalnız registry'de tanımlı anahtar, değer
     * tür kuralına göre dönüştürülür; audit + önbellek sürümü. Yetki (edit/critical+JIT) çağıran rotada.
     *
     * @param  array<string, mixed>  $values
     */
    public function set(User $actor, Website $website, array $values, string $reason): Website
    {
        $stored = is_array($website->seo_settings) ? $website->seo_settings : [];
        $before = [];
        $after = [];

        foreach ($values as $key => $value) {
            $def = SeoSettingsRegistry::definition($key);
            $before[$key] = $stored[$key] ?? $def['default'];
            $stored[$key] = match ($def['type']) {
                'bool' => (bool) $value,
                'int' => (int) $value,
                'lines', 'multi', 'rows' => array_values((array) $value),
                default => (string) $value,
            };
            $after[$key] = $stored[$key];
        }

        $website->forceFill(['seo_settings' => $stored])->save();
        $this->cache->invalidate($website);
        $this->audit->record($actor, 'seo.settings_updated', 'website', $website->id, ['values' => $before, 'reason' => $reason], ['values' => $after, 'reason' => $reason]);

        return $website;
    }

    /** Form alan adı: nokta form/validator için ayırıcıdır. */
    public static function field(string $key): string
    {
        return str_replace('.', '__', $key);
    }

    /** @return array<int, string> */
    public static function splitLines(string $text): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/\r?\n/', $text) ?: []), fn (string $v) => $v !== ''));
    }

    /** Sekme formunun mevcut değerleri (form alan adıyla). @return array<string, mixed> */
    public function formData(Website $website, string $tab): array
    {
        $out = [];

        foreach (SeoSettingsRegistry::forTab($tab) as $key => $def) {
            $value = $this->get($website, $key);
            $out[self::field($key)] = $def['type'] === 'lines' ? implode("\n", (array) $value) : $value;
        }

        return $out;
    }

    /**
     * @param  array{columns?: array<string, array{label: string, type: string, rules: array<int, string>, options?: array<string, string>}>}  $def
     * @return array<int, array<string, string>>
     */
    private function normalizeRows(array $def, mixed $raw): array
    {
        $rows = [];

        foreach (is_array($raw) ? $raw : [] as $row) {
            if (! is_array($row)) {
                continue;
            }

            $clean = [];
            $empty = true;

            foreach (array_keys($def['columns'] ?? []) as $column) {
                $clean[$column] = trim((string) ($row[$column] ?? ''));
                $empty = $empty && $clean[$column] === '';
            }

            if (! $empty) {
                $rows[] = $clean;
            }
        }

        return $rows;
    }

    /** @param  array{type: string, default: mixed}  $def */
    private function cast(array $def, mixed $value): mixed
    {
        return match ($def['type']) {
            'bool' => (bool) $value,
            'int' => (int) $value,
            'multi', 'lines', 'rows' => is_array($value) ? $value : $def['default'],
            default => (string) $value,
        };
    }

    private static function validJsonLd(string $json): bool
    {
        $decoded = json_decode($json, true);

        return is_array($decoded) && json_last_error() === JSON_ERROR_NONE;
    }
}

<?php

namespace Tests\Feature\Panel;

use App\Enums\ContentStatus;
use App\Models\Content;
use App\Models\Media;
use App\Models\SiteSection;
use App\Models\User;
use App\Models\Website;
use App\Services\ContentCache;
use App\Site\SectionLibrary;
use App\Support\ActivationJourney;
use Database\Seeders\LocationSeeder;
use Database\Seeders\SiteBlockSeeder;
use Database\Seeders\WebsiteSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Editör kapsama denetimi (faz 57): ana sayfada basılan HER bölüm tipi editör çerçevesinde seçilebilir ve tanımlı her
 * düzenlenebilir alanı (metin/markdown/görsel/madde listesi) satır içi işaretle çıkar; "Nasıl çalışır" adımları ekle/sil/
 * sırala → kaydet → yayınla → vitrin zinciri uçtan uca. Eksik component/alan varsa test raporla kırılır.
 */
class EditorCoverageTest extends TestCase
{
    use CreatesTenantFixtures;
    use RefreshDatabase;

    private Website $site;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRbac();
        $this->seed(LocationSeeder::class);
        $this->seed(WebsiteSeeder::class);
        $this->seed(SiteBlockSeeder::class);
        $this->site = Website::query()->default()->firstOrFail();
        $this->admin = $this->staff('system_admin');
    }

    /** Her tipe örnek ayar: her alan dolu olsun ki işaret basılsın. @return array<string, mixed> */
    private function sample(string $type): array
    {
        $settings = SectionLibrary::defaults($type);

        foreach (SectionLibrary::type($type)['fields'] as $key => $field) {
            $settings[$key] = match ($field['type']) {
                'text', 'textarea' => $key === 'limit' ? '3' : ($key === 'embed' ? 'https://www.google.com/maps/embed?pb=x' : ($key === 'link' ? '/blog' : "Örnek {$type} {$key}")),
                'markdown' => "**Örnek** {$key} metni.",
                'lines' => $settings[$key] ?? ['Örnek | Madde | Açıklama'],
                'cta' => ['action' => 'lead_form', 'target' => '', 'label' => 'Teklif al'],
                'media' => '',
                'media_list' => [],
                'number' => '48',
                default => $settings[$key] ?? array_key_first($field['options'] ?? ['' => '']),
            };
        }

        return $settings;
    }

    #[Test]
    public function ana_sayfadaki_her_bilesen_editorde_secilebilir_ve_her_alani_duzenlenebilir(): void
    {
        // Yazı olsun ki blog bölümü bassın; oda/olanak/plan seed'lerden.
        $post = Content::create(['website_id' => $this->site->id, 'kind' => 'post', 'slug' => 'ornek-yazi', 'title' => 'Örnek yazı', 'body' => 'Gövde.', 'category' => 'Genel']);
        $post->forceFill(['status' => ContentStatus::PUBLISHED, 'published_at' => now()->subDay()])->save();

        // Tüm bölüm tipleri (varsayılan yerleşim + kütüphanedeki diğerleri) örnek ayarlarla taslağa.
        $this->actingAs($this->admin)->get('/panel/icerik/tasarim')->assertOk();
        $rows = [];
        $order = 0;

        foreach (SiteSection::query()->where('website_id', $this->site->id)->orderBy('sort_order')->get() as $s) {
            $rows[] = ['id' => $s->id, 'type' => $s->type, 'anchor' => $s->anchor, 'is_visible' => true, 'settings' => $this->sample($s->type)];
            $order++;
        }

        foreach (array_keys(SectionLibrary::types()) as $type) {
            if (! collect($rows)->contains('type', $type)) {
                $rows[] = ['id' => null, 'type' => $type, 'anchor' => 'ek-'.str_replace('_', '-', $type), 'is_visible' => true, 'settings' => $this->sample($type)];
            }
        }

        $this->actingAs($this->admin)->put("/panel/icerik/tasarim/{$this->site->id}/taslak", ['payload' => json_encode(['sections' => $rows, 'globals' => ['texts' => [], 'footer_columns' => '', 'blocks' => []]])])->assertRedirect()->assertSessionHasNoErrors();

        $frame = $this->actingAs($this->admin)->get(URL::temporarySignedRoute('site.preview', now()->addMinutes(5), ['website' => $this->site->id, 'editor' => 1]))->assertOk()->getContent();
        $report = [];
        $missing = [];

        foreach (SiteSection::query()->where('website_id', $this->site->id)->get() as $s) {
            $def = SectionLibrary::type($s->type);
            $selectable = str_contains($frame, 'data-ofv-section="'.$s->id.'"');
            $fieldsOk = [];
            $fieldsMissing = [];

            foreach ($def['fields'] as $key => $field) {
                $marker = match ($field['type']) {
                    'text', 'textarea' => in_array($key, ['limit', 'category', 'embed', 'link', 'address'], true) ? null : 'data-ofv-field="'.$key.'"',
                    'markdown' => 'data-ofv-md="'.$key.'"',
                    'media' => 'data-ofv-image="'.$key.'"',
                    'media_list' => 'data-ofv-image="'.$key.'[]"',
                    'lines' => isset($field['columns']) ? 'data-ofv-item="'.$key.':0:' : null,
                    default => null, // select/number/cta: yalnız panelden (görsel karşılığı yok)
                };

                if ($marker === null) {
                    continue;
                }

                // Bölüm çerçevede basılmıyorsa (veri yok: meeting oda, amenities blok) alan testi anlamsız; bölüm seçilebilirliği yeter.
                if ($selectable && str_contains($frame, $marker)) {
                    $fieldsOk[] = $key;
                } elseif ($selectable) {
                    $fieldsMissing[] = $key;
                }
            }

            $report[] = sprintf('| %-14s | %s | %s | %s |', $def['label'], $selectable ? '✓' : '✗', $selectable ? '✓' : '✗', $fieldsMissing === [] ? '✓ '.implode(',', $fieldsOk) : '✗ eksik: '.implode(',', $fieldsMissing));

            if (! $selectable || $fieldsMissing !== []) {
                $missing[] = $s->type.': '.($selectable ? implode(',', $fieldsMissing) : 'çerçevede yok');
            }
        }

        fwrite(STDERR, "\n| Component | Frontend | Editor | Düzenlenebilir |\n".implode("\n", $report)."\n");
        $this->assertSame([], $missing, "Editörde eksik bileşen/alan:\n".implode("\n", $missing));
    }

    #[Test]
    public function nasil_calisir_adimlari_ekle_sil_sirala_kaydet_yayinla_vitrin(): void
    {
        $this->actingAs($this->admin)->get('/panel/icerik/tasarim')->assertOk();
        $journey = SiteSection::query()->where('type', 'journey')->firstOrFail();

        // İlk açılış: adımlar aktivasyon akışından ayara yazıldı (4 adım), bilgi kutusu dolu.
        $this->assertCount(count(ActivationJourney::steps()), $journey->settings['steps']);
        $this->assertStringContainsString('Belge kontrolü', $journey->settings['steps'][1]);
        $this->assertNotSame('', $journey->settings['note']);

        // Editör çerçevesi: her adımın ikon/başlık/açıklaması satır içi işaretli, görsel yuvası var, not ve başlık işaretli.
        $frame = $this->actingAs($this->admin)->get(URL::temporarySignedRoute('site.preview', now()->addMinutes(5), ['website' => $this->site->id, 'editor' => 1]))->assertOk()->getContent();
        foreach (array_keys($journey->settings['steps']) as $i) {
            $this->assertStringContainsString('data-ofv-item="steps:'.$i.':1"', $frame);
            $this->assertStringContainsString('data-ofv-item="steps:'.$i.':2"', $frame);
        }
        $this->assertStringContainsString('data-ofv-image="images[]"', $frame);
        $this->assertStringContainsString('data-ofv-field="note"', $frame);
        $this->assertStringContainsString('data-ofv-field="lede" data-ofv-global="texts.journey_lede"', $frame);

        // Sırala (2↔1), sil (4.), ekle (yeni), görsel ata, not değiştir, CTA → kaydet.
        $media = Media::create(['website_id' => $this->site->id, 'disk' => 'public', 'path' => 'media/adim.jpg', 'mime_type' => 'image/jpeg', 'size_bytes' => 1000, 'width' => 800, 'height' => 500, 'alt' => 'Adım görseli', 'original_name' => 'adim.jpg', 'checksum_sha256' => str_repeat('a', 64)]);
        $steps = $journey->settings['steps'];
        $new = [$steps[1], $steps[0], $steps[2], '🚀 | Yeni adım | Yeni açıklama'];
        $settings = $journey->settings;
        $settings['steps'] = $new;
        $settings['images'] = [$media->id];
        $settings['note'] = 'Yeni bilgi kutusu.';
        $settings['cta'] = ['action' => 'lead_form', 'target' => '', 'label' => 'Hemen başvurun'];
        $rows = SiteSection::query()->where('website_id', $this->site->id)->orderBy('sort_order')->get()->map(fn (SiteSection $s) => ['id' => $s->id, 'type' => $s->type, 'anchor' => $s->anchor, 'is_visible' => true, 'settings' => $s->id === $journey->id ? $settings : ($s->settings ?? [])])->values()->all();
        $this->actingAs($this->admin)->put("/panel/icerik/tasarim/{$this->site->id}/taslak", ['payload' => json_encode(['sections' => $rows, 'globals' => ['texts' => [], 'footer_columns' => '', 'blocks' => []]])])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame($new, $journey->fresh()->settings['steps']);

        // Önizleme (taslak) → yayın → vitrin: sıra, yeni adım, silinen adım yok, görsel, not, CTA.
        $preview = $this->actingAs($this->admin)->get(URL::temporarySignedRoute('site.preview', now()->addMinutes(5), ['website' => $this->site->id]))->assertOk()->getContent();
        $this->assertStringContainsString('Yeni adım', $preview);
        $this->actingAs($this->admin)->post("/panel/icerik/tasarim/{$this->site->id}/yayinla")->assertRedirect();
        app(ContentCache::class)->invalidate($this->site);
        $home = $this->get('http://localhost/')->assertOk()->getContent();
        $this->assertLessThan(strpos($home, 'Başvuru</h3>'), strpos($home, 'Belge kontrolü</h3>'), 'Adım sırası değişmedi.');
        $this->assertStringContainsString('Yeni adım', $home);
        $this->assertStringContainsString('Yeni açıklama', $home);
        $this->assertStringNotContainsString('Ödeme</h3>', $home);
        $this->assertStringContainsString('media/adim.jpg', $home);
        $this->assertStringContainsString('alt="Adım görseli"', $home);
        $this->assertStringContainsString('Yeni bilgi kutusu.', $home);
        $this->assertStringContainsString('Hemen başvurun', $home);
        $this->assertStringNotContainsString('data-ofv-', $home);

        // Bilgi kutusu boşaltılınca gizlenir.
        $settings['note'] = '';
        $rows = array_map(fn (array $r) => $r['type'] === 'journey' ? ['settings' => $settings] + $r : $r, $rows);
        $this->actingAs($this->admin)->put("/panel/icerik/tasarim/{$this->site->id}/taslak", ['payload' => json_encode(['sections' => $rows, 'globals' => ['texts' => [], 'footer_columns' => '', 'blocks' => []]])])->assertRedirect();
        $this->actingAs($this->admin)->post("/panel/icerik/tasarim/{$this->site->id}/yayinla")->assertRedirect();
        app(ContentCache::class)->invalidate($this->site);
        $this->assertStringNotContainsString('Yeni bilgi kutusu.', $this->get('http://localhost/')->assertOk()->getContent());
    }
}

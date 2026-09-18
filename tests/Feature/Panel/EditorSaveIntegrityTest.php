<?php

namespace Tests\Feature\Panel;

use App\Models\SiteSection;
use App\Models\Website;
use App\Site\SectionLibrary;
use Database\Seeders\LocationSeeder;
use Database\Seeders\SiteBlockSeeder;
use Database\Seeders\WebsiteSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Editör kayıt bütünlüğü (faz 53 düzeltmesi): her düzenlenebilir bölüm metni tek gönderimle kaydedilir, yayınla ile vitrine
 * geçer; varsayılan ayarlar (franchise CTA) ilk açılışta kayıtta olduğundan kaydetme onları silmez; editör çerçevesi bölüm
 * değerini global metinle ezmez (JS: applyGlobalText bölüm alanını atlar, boş ayar dizisi nesneye çevrilir).
 */
class EditorSaveIntegrityTest extends TestCase
{
    use CreatesTenantFixtures;
    use RefreshDatabase;

    #[Test]
    public function her_bolum_metni_kaydedilir_yayinlanir_ve_varsayilan_cta_korunur(): void
    {
        $this->seedRbac();
        $this->seed(LocationSeeder::class);
        $this->seed(WebsiteSeeder::class);
        $this->seed(SiteBlockSeeder::class);
        $site = Website::query()->default()->firstOrFail();
        $admin = $this->staff('system_admin');

        // İlk açılış: varsayılan yerleşim varsayılan ayarlarla yazılır (franchise CTA dahil); config JSON boş ayarı nesne olarak taşır.
        $page = $this->actingAs($admin)->get('/panel/icerik/tasarim')->assertOk()->getContent();
        $franchise = SiteSection::query()->where('type', 'franchise')->firstOrFail();
        $this->assertSame('page', $franchise->settings['cta']['action'] ?? null);
        $this->assertStringNotContainsString('&quot;settings&quot;:[]', $page);

        // Her metin alanına farklı değer: tek gönderim.
        $sections = SiteSection::query()->where('website_id', $site->id)->orderBy('sort_order')->get();
        $expected = [];
        $payload = ['sections' => $sections->map(function (SiteSection $s) use (&$expected) {
            $settings = $s->settings ?? [];

            foreach (SectionLibrary::type($s->type)['fields'] as $key => $field) {
                if (in_array($field['type'], ['text', 'textarea'], true) && $key !== 'limit') { // limit sayısal normalize edilir
                    $settings[$key] = $expected[$s->id.':'.$key] = "Kayıt {$s->id} {$key} ok";
                }
            }

            return ['id' => $s->id, 'type' => $s->type, 'anchor' => $s->anchor, 'is_visible' => true, 'settings' => $settings];
        })->values()->all(), 'globals' => ['texts' => ['nav_solutions' => 'Çözümlerimiz'], 'footer_columns' => '', 'blocks' => []]];

        $this->actingAs($admin)->put("/panel/icerik/tasarim/{$site->id}/taslak", ['payload' => json_encode($payload)])->assertRedirect()->assertSessionHasNoErrors();

        foreach ($expected as $key => $value) {
            [$id, $field] = explode(':', $key);
            $this->assertSame($value, SiteSection::query()->findOrFail((int) $id)->settings[$field] ?? null, "Alan kaydedilmedi: {$key}");
        }

        $this->assertSame('page', $franchise->fresh()->settings['cta']['action'] ?? null, 'Kaydetme varsayılan CTA\'yı silmemeli.');

        // Editör çerçevesi ve yayın: bölüm değeri global metnin önünde.
        $frame = $this->actingAs($admin)->get(URL::temporarySignedRoute('site.preview', now()->addMinutes(5), ['website' => $site->id, 'editor' => 1]))->assertOk()->getContent();
        $this->assertStringContainsString('Kayıt 3 title ok', $frame);
        $this->actingAs($admin)->post("/panel/icerik/tasarim/{$site->id}/yayinla")->assertRedirect();
        $home = $this->get('http://localhost/')->assertOk()->getContent();
        $this->assertStringContainsString('Kayıt 3 title ok', $home);
        $this->assertStringContainsString('Kayıt 1 eyebrow ok', $home);
        $this->assertStringContainsString('>Çözümlerimiz<', $home);
        $this->assertStringContainsString('Franchise Başvurusu', $home);
        $this->assertStringNotContainsString('dört başlangıç noktası', $home);
    }
}

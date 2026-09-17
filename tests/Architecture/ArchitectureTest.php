<?php

namespace Tests\Architecture;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Mimari kurallar — kaynak kodu tarayarak zorlanır.
 *
 * NEDEN PHPStan CUSTOM RULE DEĞİL: Bu kurallar PHPStan'ın sürümler arası
 * değişen AST API'sine bağımlı olmadan çalışmalı. Burada PHP'nin kendi
 * tokenizer'ı kullanılıyor; kurallar `php artisan test` ile birlikte koşuyor
 * ve kalite kapısının (§76) parçası oluyor.
 *
 * Bu testler "kod çalışıyor mu" değil, "kod kurala uyuyor mu" sorusunu
 * cevaplar. Bir kuralı geçmek için testi gevşetmek YASAKTIR (§76).
 */
class ArchitectureTest extends TestCase
{
    private static function appPath(): string
    {
        return dirname(__DIR__, 2).'/app';
    }

    /** @return array<string, string> dosya yolu => içerik */
    private static function sources(string $subdir = ''): array
    {
        $base = self::appPath().($subdir !== '' ? '/'.$subdir : '');
        $files = [];

        if (! is_dir($base)) {
            return $files;
        }

        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base));

        foreach ($it as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[$file->getPathname()] = file_get_contents($file->getPathname());
            }
        }

        return $files;
    }

    /** Yorum ve string literal'leri çıkarır — kural ihlali yalnızca GERÇEK kodda aranır. */
    private static function codeOnly(string $source): string
    {
        $out = '';

        foreach (token_get_all($source) as $token) {
            if (is_array($token)) {
                if (in_array($token[0], [T_COMMENT, T_DOC_COMMENT, T_CONSTANT_ENCAPSED_STRING, T_INLINE_HTML], true)) {
                    continue;
                }
                $out .= $token[1];

                continue;
            }
            $out .= $token;
        }

        return $out;
    }

    #[Test]
    public function controllerlar_dogrudan_veritabanina_erismez(): void
    {
        // CLAUDE.md kuralı. Controller yalnızca doğrulanmış context'i alır,
        // servisi çağırır ve cevabı biçimlendirir. Sorgu servistedir.
        $violations = [];

        foreach (self::sources('Http/Controllers') as $path => $source) {
            $code = self::codeOnly($source);

            // DB facade
            if (preg_match('/\bDB::\w+\s*\(/', $code)) {
                $violations[] = basename($path).' → DB:: kullanıyor';
            }

            // Model::where(...) / Model::find(...) gibi statik sorgular
            if (preg_match('/\b[A-Z]\w+::(where|find|findOrFail|all|first|firstOrFail|create|updateOrCreate|query|whereKey|pluck|count)\s*\(/', $code, $m)) {
                $violations[] = basename($path).' → '.$m[0].' (Eloquent sorgusu)';
            }
        }

        $this->assertSame([], $violations, "Controller'dan doğrudan DB erişimi:\n".implode("\n", $violations));
    }

    #[Test]
    public function sirket_durumuna_yalnizca_aktivasyon_servisi_yazar(): void
    {
        // State machine baypas edilirse geçiş audit'i kaybolur.
        $allowed = ['CompanyActivationService.php'];
        $violations = [];

        foreach (self::sources() as $path => $source) {
            if (in_array(basename($path), $allowed, true)) {
                continue;
            }

            $code = self::codeOnly($source);

            // $company->status = ... (ATAMA)
            // Negatif lookahead sart: '=(?![=>])' olmadan regex '===' ve '=>'
            // ifadelerini de atama sanir ve karsilastirmalari yanlis yere
            // ihlal olarak isaretler. Bu kural ilk yazildiginda tam olarak
            // bu yanlis pozitifi uretti.
            if (preg_match('/\$\w*[Cc]ompany\w*->status\s*=(?![=>])/', $code, $m)) {
                $violations[] = basename($path).' → '.trim($m[0]);
            }
        }

        $this->assertSame([], $violations, "companies.status'a izinsiz yazma:\n".implode("\n", $violations));
    }

    #[Test]
    public function tenant_scope_baypasi_yalnizca_izinli_yerlerde(): void
    {
        // withoutTenantScope() her çağrısı bir güvenlik kararıdır. Allowlist
        // dışında kullanım code review'a gelmeden merge edilemesin diye
        // burada kırılır. Yeni bir yer eklemek, bu listeyi BİLİNÇLİ olarak
        // genişletmeyi gerektirir.
        $allowed = [
            'TenantContext.php',            // resolveCompany: tenant kontrolünü kendisi yapar
            'AuthorizationService.php',     // şirketten organizasyon çözümlemesi
            'BelongsToTenant.php',          // trait'in kendi tanımı
            'KycQueueService.php',          // pendingCounts: yalnızca personel, yalnızca org başına ADET (bkz. sınıf başlığı)
            'AuditLogService.php',          // denetim kaydı: global audit.view, salt okunur, tüm organizasyonlar tanım gereği (bkz. sınıf başlığı)
            'BookingService.php',           // uygunluk/çakışma tüm şirketlere bakmak zorunda; masa/genel liste yalnız booking.view (lokasyon/global) rotasından (bkz. sınıf başlığı)
            'ReportService.php',            // raporlar: analytics.view (global), şirketler duruma göre yalnız ADET — satır/isim dönmez (bkz. sınıf başlığı)
            'InvoiceService.php',           // faturalar/tahsilat: finans listesi, sayaçlar, numara sırası, gecikme zamanlayıcısı invoice.view (global); müşteri tarafı forCompany scope içinde (bkz. sınıf başlığı)
            'SpaceService.php',             // masa/ofis envanteri: doluluk ve tahsis listeleri space.view (global) rotasından, zamanlayıcı; müşteri tarafı forCompany scope içinde (bkz. sınıf başlığı)
            'SubscriptionService.php',      // üyelikler: finans listesi/sayaçlar subscription.view (global) rotasından, süre dolumu zamanlayıcı; müşteri tarafı forCompany scope içinde (bkz. sınıf başlığı)
        ];

        $violations = [];

        foreach (self::sources() as $path => $source) {
            if (in_array(basename($path), $allowed, true)) {
                continue;
            }

            if (str_contains(self::codeOnly($source), 'withoutTenantScope')) {
                $violations[] = basename($path);
            }
        }

        $this->assertSame([], $violations, "İzinsiz tenant scope baypası:\n".implode("\n", $violations));
    }

    #[Test]
    public function matristeki_her_izin_ya_kodda_kullanilir_ya_da_planli_listede_gerekcelidir(): void
    {
        // Audit S-7: rollerde atanmış ama hiçbir route/gate/görünüm/serviste geçmeyen izin "görünmez yetki"dir.
        // Ya kodda kullanılır ya da rbac_planned_permissions.txt'de gerekçesiyle durur; kullanılmaya
        // başlanan izin listeden çıkarılmak zorundadır (iki yönlü kontrol).
        $csv = array_map('str_getcsv', array_slice(file(dirname(__DIR__, 2).'/database/seeders/data/rbac_scope_permission_matrix.csv', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES), 1));
        $permissions = array_values(array_unique(array_map(fn (array $row) => $row[1], $csv)));

        $planned = [];
        foreach (file(dirname(__DIR__, 2).'/database/seeders/data/rbac_planned_permissions.txt', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            if (! str_starts_with(trim($line), '#')) {
                $planned[] = trim(explode('|', $line)[0]);
            }
        }

        $haystack = '';
        foreach (['app', 'routes', 'resources/views'] as $dir) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(dirname(__DIR__, 2).'/'.$dir));
            foreach ($it as $file) {
                if ($file->isFile() && str_ends_with($file->getFilename(), '.php')) {
                    $haystack .= file_get_contents($file->getPathname());
                }
            }
        }

        $unused = [];
        $stale = [];
        foreach ($permissions as $permission) {
            // Kimlik sınırı: lead.create ≠ lead.created; permission:x,company ve a|b biçimleri sayılır.
            $used = preg_match('/(?<![\w.])'.preg_quote($permission, '/').'(?![\w.])/', $haystack) === 1;
            $isPlanned = in_array($permission, $planned, true);

            if (! $used && ! $isPlanned) {
                $unused[] = $permission;
            }
            if ($used && $isPlanned) {
                $stale[] = $permission;
            }
        }

        $this->assertSame([], $unused, "Kodda kullanılmayan ve planlı listede olmayan izinler:\n".implode("\n", $unused));
        $this->assertSame([], $stale, "Artık kullanılan ama hâlâ planlı listede duran izinler (listeden çıkarın):\n".implode("\n", $stale));
    }

    #[Test]
    public function servisler_request_ve_session_a_dogrudan_bagli_degildir(): void
    {
        // Servis katmanı HTTP'den bağımsız olmalı ki queue job'ından da
        // çağrılabilsin. TenantContext istisnadır: session'ı bilinçli sarar.
        $allowed = ['TenantContext.php', 'ContextSwitchService.php'];
        $violations = [];

        foreach (self::sources('Services') as $path => $source) {
            if (in_array(basename($path), $allowed, true)) {
                continue;
            }

            $code = self::codeOnly($source);

            foreach (['request(', 'Session::', 'session(', '$_GET', '$_POST'] as $needle) {
                if (str_contains($code, $needle)) {
                    $violations[] = basename($path).' → '.$needle;
                }
            }
        }

        $this->assertSame([], $violations, "Servis katmanında HTTP bağımlılığı:\n".implode("\n", $violations));
    }

    #[Test]
    public function gate_before_kullanilmaz(): void
    {
        // Gate::before super_admin'e koşulsuz geçiş verir ve "Super Admin !=
        // Root" ilkesiyle tüm JIT mekanizmasını tek satırda iptal eder.
        $violations = [];

        foreach (self::sources() as $path => $source) {
            if (preg_match('/Gate::before\s*\(/', self::codeOnly($source))) {
                $violations[] = basename($path);
            }
        }

        $this->assertSame([], $violations, 'Gate::before kullanımı: '.implode(', ', $violations));
    }

    #[Test]
    public function migrationlar_down_metodu_tanimlar(): void
    {
        // Geri alınamayan migration, başarısız deploy'da sistemi kilitler.
        $violations = [];
        $dir = dirname(__DIR__, 2).'/database/migrations';

        foreach (glob($dir.'/*.php') as $path) {
            if (! str_contains(file_get_contents($path), 'public function down()')) {
                $violations[] = basename($path);
            }
        }

        $this->assertSame([], $violations, "down() tanımlamayan migration:\n".implode("\n", $violations));
    }
}

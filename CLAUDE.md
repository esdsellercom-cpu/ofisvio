# Ofisvio — çalışma kuralları

Laravel 13 / PHP 8.3. Sanal ofis & coworking SaaS; çok kiracılı (organization → company).
Dil: kod yorumları, commit mesajları ve UI **Türkçe**; tanımlayıcılar İngilizce.

## §76 Kalite kapısı — her değişiklikten sonra dördü de yeşil olmalı

```
./vendor/bin/pint --test
./vendor/bin/phpstan analyse        # level 6, 0 hata
php artisan test                    # Unit + Feature + Architecture
npm run build
```

Kırmızı testi geçirmek için test **gevşetilmez**, kök neden düzeltilir. PHPStan seviyesi
düşürülmez, `@phpstan-ignore` / baseline eklenmez. `tests/Architecture/ArchitectureTest.php`
mimari kuralları kaynak taramasıyla zorlar; allowlist'e ekleme yalnızca gerekçeli yorumla.

## Zincir: auth → staff.2fa → tenant → permission (bkz. routes/panel.php)

- Personel (global internal rol) doğrulanmış 2FA olmadan `/panel/hesap*` dışında hiçbir ekrana giremez (`EnsureStaffTwoFactor`). Testlerde `staff()` fixture'ı 2FA'lı personel üretir; zorunluluk testleri `staffWithoutTwoFactor()` kullanır.

- **Yetki route'ta verilir**, controller'da değil: `->middleware('permission:<izin>[,<kapsam>[,<kaynak tipi>,<kaynak parametresi>]]')`.
  `|` alternatif izindir. İzin adları `database/seeders/data/rbac_scope_permission_matrix.csv`'den gelir; CSV tek kaynaktır.
- **Controller'da DB sorgusu yok.** `TenantContext::toArray()` ile doğrulanmış context alınır, servis çağrılır, cevap biçimlendirilir.
- **Servisler HTTP'den bağımsız**: `request()`, `session()` yok (TenantContext ve ContextSwitchService istisna).
- **`companies.status`'a yalnızca `CompanyActivationService` yazar** (state machine + audit).
- **`withoutTenantScope()` her çağrısı güvenlik kararıdır**; ArchitectureTest allowlist'inde gerekçesiyle yer almalı.
- İç içe route'larda `->scopeBindings()` zorunlu; çocuk parametre adı ebeveynin **çoğul ilişki metoduyla** eşleşmeli
  (`{kycDocument}` → `Company::kycDocuments()`, `{userRole}` → `Company::userRoles()`).
- `permission:<izin>,location` organizasyon bağlamı istemez (lokasyon Ofisvio şubesidir); context yalnız `location_id`. Lokasyon kapsamlı internal rol (resepsiyon) `user_roles.location_id` ile atanır (`UserAdminService::locationScopedRoles`) ve 2FA zorunluluğuna girer.
- Tenant sınırı ihlali **404** döner (403 kaydın varlığını sızdırır); context yoksa 409 → tarayıcıda seçim ekranı.
- Tenant scope taşımayan modele (Content, Website) tenant rotasından erişim: parametre **int** kalır (model binding yok), servis organizasyona süzer (`ContentService::findForOrganization`), null → 404. Bkz. `SiteController`.
- `Gate::before` yasak ("Super Admin != Root"); JIT izinleri (`requires_jit`) `allows()` ile, rolde-var-mı sorusu `can()` ile.
- Sahte ticari veri yasak: seeder yalnızca referans veri (roller, lokasyonlar, vitrin blokları `site_blocks.json`). Kullanıcı/şifre seed edilmez; hesaplar `ofisvio:bootstrap-accounts` (env) ya da `ofisvio:make-admin`.
- **Rezervasyon durumu yalnızca `BookingService::transition` yazar** (`BookingStatus` durum makinesi); onay politikası/uygunluk kuralları `SettingsService` ayarlarından. Bildirim: servis doğrudan sağlayıcı çağırmaz — `NotificationService::dispatch` → kuyruk → kanal → `*ProviderInterface` (Gateway). Alıcı telefon/e-posta yalnız DB (`notification_recipients`); kritik değişiklikler `AuditService::record`.
- **Ticari/CMS içerik config'te olmaz** (fiyat, telefon, e-posta, liste): tek kaynak veritabanı + panel. `config/ofisvio.php` yalnız teknik sabit taşır; `MockDataDetectionTest` bunu zorlar. Tarayıcı depolaması (localStorage vb.) ve JS'ten doğrudan HTTP çağrısı yasak.

## Görünüm katmanı

- Tasarım sistemi `public/css/ofisvio.css` (derlenmez, `<link>` ile). `resources/css/app.css` boş giriş noktası — public asset'i `@import` ETME (Vite build kırılır).
- Ana sayfa bölümleri `SiteBuilderService` (taslak `site_sections` → yayın `site_revisions`); vitrin yalnız yayınlanmış anlık görüntüyü basar, `@include('site.sections.<tip>')`. Yeni bölüm tipi = `SectionLibrary` + `resources/views/site/sections/<tip>.blade.php`.
- Panel sayfaları `layouts.panel`'i extend eder; `$activeOrganization`, `$isStaff`, `$canSwitchOrganization` `PanelLayoutComposer`'dan gelir (`panel.*` görünümlerine de bağlı).
- Türkçe metinler `lang/tr/*` ve `lang/tr.json`; rol etiketleri `lang/tr/roles.php`.

## Windows notları

PowerShell 5.1: `Get-Content -Raw` ANSI okur — UTF-8 dosyaları `[IO.File]::ReadAllText($f, [Text.Encoding]::UTF8)` ile oku, BOM'suz yaz.
Vendor binary'leri `.\vendor\bin\pint.bat`, `.\vendor\bin\phpstan.bat`.

Yol haritası: `ROADMAP.md`. Yeni modül kalıbı: `routes/BOOTSTRAP.md`. Üretime alma: `DEPLOY.md` + `php artisan ofisvio:doctor` (üretimde hata → çıkış 1). `DatabaseSeeder` yalnız referans veri çağırır; kullanıcı seed'i eklenmez.

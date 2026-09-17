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

## Zincir: auth → account.active → verified → staff.2fa → tenant → permission (bkz. routes/panel.php)

- Personel (global internal rol) doğrulanmış 2FA olmadan `/panel/hesap*` dışında hiçbir ekrana giremez (`EnsureStaffTwoFactor`; `security.require_customer_2fa` açıksa şirket sahibi/yöneticisi de). Panel `verified` ister: davetli şifre belirleyince doğrulanır. Şifre politikası `AppServiceProvider` (`Password::defaults`). Matristeki izin kodda kullanılmıyorsa `rbac_planned_permissions.txt`'de gerekçeli olmalı (ArchitectureTest). Testlerde `staff()` fixture'ı 2FA'lı personel üretir; zorunluluk testleri `staffWithoutTwoFactor()` kullanır. Hesap durumu/oturumlar/giriş geçmişi `AccountSecurityService` (`users.status`, `sessions`, `login_events`); askıdaki hesap girişte genel hata alır, açık oturumu `account.active` düşürür. Giriş olayları `RecordLoginEvent`'e gider — sentetik oturumlar (perf ölçümü) `Auth::setUser/forgetUser` kullanır. `User` `#[Fillable]` dışı alanlar (`status`, `email_verified_at`) yalnız `forceFill`.

- **Yetki route'ta verilir**, controller'da değil: `->middleware('permission:<izin>[,<kapsam>[,<kaynak tipi>,<kaynak parametresi>]]')`.
  `|` alternatif izindir. İzin adları `database/seeders/data/rbac_scope_permission_matrix.csv`'den gelir; CSV tek kaynaktır.
- **Controller'da DB sorgusu yok.** `TenantContext::toArray()` ile doğrulanmış context alınır, servis çağrılır, cevap biçimlendirilir.
- **Servisler HTTP'den bağımsız**: `request()`, `session()` yok (TenantContext ve ContextSwitchService istisna).
- **`companies.status`'a yalnızca `CompanyActivationService` yazar** (state machine + audit).
- **`withoutTenantScope()` her çağrısı güvenlik kararıdır**; ArchitectureTest allowlist'inde gerekçesiyle yer almalı.
- İç içe route'larda `->scopeBindings()` zorunlu; çocuk parametre adı ebeveynin **çoğul ilişki metoduyla** eşleşmeli
  (`{kycDocument}` → `Company::kycDocuments()`, `{userRole}` → `Company::userRoles()`).
- `permission:<izin>,location` organizasyon bağlamı istemez (lokasyon Ofisvio şubesidir); context yalnız `location_id`. `permission:<izin>,anylocation` lokasyonsuz liste ekranı içindir: global YA DA herhangi bir lokasyon grant'i geçer; controller `AuthorizationService::locationIdsWith` (null = hepsi) ile süzer, yabancı lokasyonun kaydı 404 (bkz. `SpaceController`). Lokasyon kapsamlı internal rol (resepsiyon) `user_roles.location_id` ile atanır (`UserAdminService::locationScopedRoles`) ve 2FA zorunluluğuna girer.
- Tenant sınırı ihlali **404** döner (403 kaydın varlığını sızdırır); context yoksa 409 → tarayıcıda seçim ekranı.
- Tenant scope taşımayan modele (Content, Website) tenant rotasından erişim: parametre **int** kalır (model binding yok), servis organizasyona süzer (`ContentService::findForOrganization`), null → 404. Bkz. `SiteController`.
- `Gate::before` yasak ("Super Admin != Root"); JIT izinleri (`requires_jit`) `allows()` ile, rolde-var-mı sorusu `can()` ile.
- Sahte ticari veri yasak: seeder yalnızca referans veri (roller, lokasyonlar, hizmet kataloğu `services.json`, vitrin blokları `site_blocks.json`). Hizmetler tek kaynak `services` tablosudur; lokasyon ekranı hizmet oluşturmaz, seçer. Kullanıcı/şifre seed edilmez; hesaplar `ofisvio:bootstrap-accounts` (env) ya da `ofisvio:make-admin`.
- **Masa/ofis envanteri** `spaces` (saatlik odalar `rooms`), tahsis yalnız `SpaceService::assign/end` (satır kilidi, kapasite; audit); doluluk `SpaceService::occupancy`.
- **Üyelik/fatura durumu yalnızca servis yazar**: `SubscriptionService` (active/expired/cancelled), `InvoiceService` (draft/issued/overdue/paid/cancelled; tahsilat `recordPayment`); fatura iptali JIT'li (`invoice.cancel`). Etkinlik kaydı `EventService::register` (kontenjan, KVKK), franchise başvurusu `FranchiseService::apply`. **Tutarlar veritabanında ve serviste kuruş (minor unit) tam sayıdır** — gösterim `money()` / `App\Support\Money::format`, form girdisi (büyük birim, virgül/nokta) `Money::parse`, doğrulama `Money::RULE`, yüzde `Money::percent`; para birimi ayardan (`general.currency`). Tarih sütunlarında `whereDate` (SQLite/MySQL uyumu).
- **Rezervasyon durumu yalnızca `BookingService::transition` yazar** (`BookingStatus` durum makinesi); onay politikası/uygunluk kuralları `SettingsService` ayarlarından. Bildirim: servis doğrudan sağlayıcı çağırmaz — `NotificationService::dispatch` → kuyruk → kanal → `*ProviderInterface` (Gateway). Yeni olay = `NotificationEvents::registry()` kaydı (varsayılan kurallar `seedDefaultRules` ile olay bazlı gelir); müşteri muhatabı `MembershipService::primaryContact`. Zamanlayıcılar `routes/console.php`; hatırlatmalar tek seferlik damga kolonuyla (`due_reminder_sent_at`, `expiring_notice_sent_at`). Alıcı telefon/e-posta yalnız DB (`notification_recipients`); kritik değişiklikler `AuditService::record`.
- **Ticari/CMS içerik config'te olmaz** (fiyat, telefon, e-posta, liste): tek kaynak veritabanı + panel. `config/ofisvio.php` yalnız teknik sabit taşır; `MockDataDetectionTest` bunu zorlar. Tarayıcı depolaması (localStorage vb.) ve JS'ten doğrudan HTTP çağrısı yasak.

## Görünüm katmanı

- SEO: yalın ayar `websites.seo_*` (+ `/panel/seo`), gelişmiş ayar `websites.seo_settings` JSON — tanım `App\Seo\SeoSettingsRegistry` (yeni ayar = oraya satır; sekme modu izni belirler: edit/critical/integration/entity), okuma `SeoSettingsService::for/get`. Head verisi `SeoService::head` (composer basar), robots/sitemap/llms/HTML site haritası `Site\SeoController`. `SiteSeoPolicy` GLOBAL middleware: Host→Website çözümlemesi + vitrin yönlendirme/başlık politikası (panel/kimlik yolları atlanır; test istemcisi sondaki eğik çizgiyi kırpar — middleware doğrudan çağrılır). Dış istek (IndexNow) yalnız `Integrations\Gateway`.
- Tasarım sistemi `public/css/ofisvio.css` (derlenmez, `<link>` ile). `resources/css/app.css` boş giriş noktası — public asset'i `@import` ETME (Vite build kırılır).
- Ana sayfa bölümleri `SiteBuilderService` (taslak `site_sections` → yayın `site_revisions`); vitrin yalnız yayınlanmış anlık görüntüyü basar, `@include('site.sections.<tip>')`. Yeni bölüm tipi = `SectionLibrary` + `resources/views/site/sections/<tip>.blade.php`.
- Panel sayfaları `layouts.panel`'i extend eder; `$activeOrganization`, `$isStaff`, `$canSwitchOrganization`, `$panelMenu`, `$uiTheme` `PanelLayoutComposer`'dan gelir (`panel.*` görünümlerine de bağlı). Panel kabuğu `public/css/panel.css` (token eşlemesi + `ap-` bileşenleri; koyu tema `html[data-theme]`), menü `App\View\Menu\PanelMenu` (yeni modül = oraya öge; rozet = `PanelBadgeService` tek sorgu).
- Türkçe metinler `lang/tr/*` ve `lang/tr.json`; rol etiketleri `lang/tr/roles.php`.

## Windows notları

PowerShell 5.1: `Get-Content -Raw` ANSI okur — UTF-8 dosyaları `[IO.File]::ReadAllText($f, [Text.Encoding]::UTF8)` ile oku, BOM'suz yaz.
Vendor binary'leri `.\vendor\bin\pint.bat`, `.\vendor\bin\phpstan.bat`.

Yol haritası: `ROADMAP.md`. Yeni modül kalıbı: `routes/BOOTSTRAP.md`. Üretime alma: `DEPLOY.md` + `php artisan ofisvio:doctor` (üretimde hata → çıkış 1). `DatabaseSeeder` yalnız referans veri çağırır; kullanıcı seed'i eklenmez.

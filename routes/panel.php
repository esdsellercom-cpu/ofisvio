<?php

/**
 * Panel route'ları — zincir: auth -> tenant -> permission.
 *
 * Yetki BURADA verilir, controller'da değil (bkz. routes/BOOTSTRAP.md).
 * permission:<izin>[,<kapsam>[,<kaynak tipi>,<kaynak parametresi>]]
 *
 * Üç grup:
 *   1) auth                : hesap/güvenlik — personel 2FA'yı burada kurar,
 *                            bu yüzden 2FA zorunluluğunun dışındadır.
 *   2) auth + staff.2fa    : organizasyon seçimi, müşteri açılışı — context
 *                            olmadan çalışmak ZORUNDA olan ekranlar.
 *   3) + tenant            : geri kalan her şey.
 */

use App\Http\Controllers\Panel\AccountController;
use App\Http\Controllers\Panel\AuditController;
use App\Http\Controllers\Panel\BookingController;
use App\Http\Controllers\Panel\BookingDeskController;
use App\Http\Controllers\Panel\CacheController;
use App\Http\Controllers\Panel\CompanyController;
use App\Http\Controllers\Panel\ContentController;
use App\Http\Controllers\Panel\ContentDraftController;
use App\Http\Controllers\Panel\ContextController;
use App\Http\Controllers\Panel\DashboardController;
use App\Http\Controllers\Panel\GeoController;
use App\Http\Controllers\Panel\KycController;
use App\Http\Controllers\Panel\LeadController;
use App\Http\Controllers\Panel\LocationMediaController;
use App\Http\Controllers\Panel\MediaController;
use App\Http\Controllers\Panel\MembershipController;
use App\Http\Controllers\Panel\NotificationController;
use App\Http\Controllers\Panel\OnboardingController;
use App\Http\Controllers\Panel\PerformanceController;
use App\Http\Controllers\Panel\RoomController;
use App\Http\Controllers\Panel\SeoController;
use App\Http\Controllers\Panel\SettingsController;
use App\Http\Controllers\Panel\SiteBlockController;
use App\Http\Controllers\Panel\SiteBuilderController;
use App\Http\Controllers\Panel\SiteController;
use App\Http\Controllers\Panel\SiteSeoController;
use App\Http\Controllers\Panel\UserController;
use App\Http\Controllers\Panel\WebsiteController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->prefix('panel')->name('panel.')->group(function () {

    // --- Hesap — 2FA zorunluluğunun DIŞINDA: kurulumun yapıldığı yer -------
    // Profil/şifre formları Fortify route'larına gider. Güvenlik sayfası
    // password.confirm ister; Fortify'ın 2FA POST'ları da aynı onayı kullanır.
    Route::get('/hesap', [AccountController::class, 'show'])->name('account');
    Route::get('/hesap/guvenlik', [AccountController::class, 'security'])
        ->middleware('password.confirm')
        ->name('account.security');

    // --- Geri kalan her şey: personel için doğrulanmış 2FA şart -------------
    Route::middleware('staff.2fa')->group(function () {

        // --- Context'siz ekranlar ---------------------------------------
        Route::get('/organizasyon', [ContextController::class, 'select'])->name('context.select');
        Route::post('/organizasyon', [ContextController::class, 'switch'])->middleware('throttle:context-switch')->name('context.switch');

        // Talepler / CRM v1 — siteden gelen teklif ve ön rezervasyon talepleri (audit bulgusu: ekranı yoktu).
        Route::prefix('talepler')->name('leads.')->group(function () {
            Route::get('/', [LeadController::class, 'index'])->middleware('permission:lead.view')->name('index');
            Route::get('/{lead}', [LeadController::class, 'show'])->middleware('permission:lead.view')->name('show');
            Route::put('/{lead}', [LeadController::class, 'update'])->middleware('permission:lead.assign')->name('update');
        });

        // Performans (F9): performance.view görüntüler; performance.audit ölçer/doctor koşar.
        Route::prefix('performans')->name('performance.')->group(function () {
            Route::get('/', [PerformanceController::class, 'index'])->middleware('permission:performance.view')->name('index');
            Route::post('/olc', [PerformanceController::class, 'measure'])->middleware('permission:performance.audit')->name('measure');
            Route::get('/doctor', [PerformanceController::class, 'doctor'])->middleware('permission:performance.audit')->name('doctor');
        });

        // Rezervasyon (booking v1) — personel tarafı, tenant context'siz.
        // Genel liste booking.view (global: operations_admin). Lokasyon masası
        // booking.view,location: resepsiyon kendi şubesi (user_roles.location_id),
        // global rol hepsi. Masadan açma booking.create,location ya da JIT'li
        // admin_override; masadan iptal YALNIZ admin_override (JIT, kaynak = lokasyon).
        Route::prefix('rezervasyonlar')->name('bookings.')->group(function () {
            Route::get('/', [BookingDeskController::class, 'index'])->middleware('permission:booking.view')->name('index');
            Route::get('/lokasyon/{location}', [BookingDeskController::class, 'location'])->middleware('permission:booking.view|booking.admin_override,location')->name('location');
            Route::get('/lokasyon/{location}/takvim', [BookingDeskController::class, 'calendar'])->middleware('permission:booking.view|booking.admin_override,location')->name('calendar');
            Route::post('/lokasyon/{location}', [BookingDeskController::class, 'store'])->middleware('permission:booking.create|booking.admin_override,location,'.BookingDeskController::RESOURCE.',location')->name('location.store');
            Route::post('/lokasyon/{location}/jit', [BookingDeskController::class, 'requestJit'])
                ->middleware(['permission:booking.view|booking.admin_override,location', 'throttle:jit-request'])->name('location.jit');

            // Detay + eylemler (§10): onay/red booking.approve; giriş/tamamlandı/gelmedi/not/yeniden planlama
            // booking.manage; iptal yalnız admin_override (JIT). Hepsi lokasyon kapsamlı — kayıt lokasyona ait olmalı.
            Route::prefix('/lokasyon/{location}/{booking}')->where(['booking' => '[0-9]+'])->group(function () {
                $view = 'permission:booking.view|booking.admin_override,location';
                Route::get('/', [BookingDeskController::class, 'show'])->middleware($view)->name('show');
                Route::post('/onayla', [BookingDeskController::class, 'approve'])->middleware('permission:booking.approve,location')->name('approve');
                Route::post('/reddet', [BookingDeskController::class, 'reject'])->middleware('permission:booking.approve,location')->name('reject');
                Route::post('/giris', [BookingDeskController::class, 'checkIn'])->middleware('permission:booking.manage,location')->name('checkin');
                Route::post('/tamamla', [BookingDeskController::class, 'complete'])->middleware('permission:booking.manage,location')->name('complete');
                Route::post('/gelmedi', [BookingDeskController::class, 'noShow'])->middleware('permission:booking.manage,location')->name('noshow');
                Route::put('/not', [BookingDeskController::class, 'note'])->middleware('permission:booking.manage,location')->name('note');
                Route::put('/planla', [BookingDeskController::class, 'reschedule'])->middleware('permission:booking.manage,location')->name('reschedule');
                Route::post('/iptal', [BookingDeskController::class, 'cancel'])
                    ->middleware('permission:booking.admin_override,location,'.BookingDeskController::RESOURCE.',location')->name('location.cancel');
            });
        });

        // Ayar merkezi (§31–33): settings.view görür, settings.manage yazar; ?lokasyon= üzerine yazma.
        Route::get('/ayarlar', [SettingsController::class, 'index'])->middleware('permission:settings.view|settings.manage')->name('settings.index');
        Route::put('/ayarlar', [SettingsController::class, 'update'])->middleware('permission:settings.manage')->name('settings.update');

        // Bildirim merkezi (§16–18): kurallar, alıcılar, şablonlar, günlük; gelen kutusu her kullanıcı.
        Route::prefix('bildirimler')->name('notifications.')->group(function () {
            Route::get('/gelen', [NotificationController::class, 'inbox'])->name('inbox');
            Route::post('/gelen/okundu', [NotificationController::class, 'markRead'])->name('inbox.read');
            Route::get('/', [NotificationController::class, 'index'])->middleware('permission:notification.view|notification.manage')->name('index');
            Route::put('/kurallar', [NotificationController::class, 'saveRules'])->middleware('permission:notification.manage')->name('rules');
            Route::post('/alicilar', [NotificationController::class, 'storeRecipient'])->middleware('permission:notification.manage')->name('recipients.store');
            Route::put('/alicilar/{recipient}', [NotificationController::class, 'updateRecipient'])->middleware('permission:notification.manage')->name('recipients.update');
            Route::delete('/alicilar/{recipient}', [NotificationController::class, 'destroyRecipient'])->middleware('permission:notification.manage')->name('recipients.destroy');
            Route::post('/alicilar/{recipient}/durum', [NotificationController::class, 'toggleRecipient'])->middleware('permission:notification.manage')->name('recipients.toggle');
            Route::put('/sablonlar', [NotificationController::class, 'saveTemplate'])->middleware('permission:notification.manage|notification_template.manage')->name('templates');
        });

        // Denetim kaydı (audit.view; global, salt okunur).
        Route::get('/denetim', [AuditController::class, 'index'])->middleware('permission:audit.view')->name('audit.index');

        // Kullanıcı yönetimi (faz 29) — personel daveti + global rol; user.manage.
        Route::prefix('kullanicilar')->name('users.')->middleware('permission:user.manage')->group(function () {
            Route::get('/', [UserController::class, 'index'])->name('index');
            Route::get('/yeni', [UserController::class, 'create'])->name('create');
            Route::post('/', [UserController::class, 'store'])->middleware('throttle:invite')->name('store');
            Route::get('/{user}', [UserController::class, 'show'])->name('show');
            Route::post('/{user}/rol', [UserController::class, 'assignRole'])->name('roles.assign');
            Route::post('/{user}/rol/{userRole}/askiya-al', [UserController::class, 'suspendRole'])->name('roles.suspend');
            Route::post('/{user}/rol/{userRole}/etkinlestir', [UserController::class, 'reactivateRole'])->name('roles.reactivate');
            Route::post('/{user}/davet', [UserController::class, 'resendInvite'])->middleware('throttle:invite')->name('resend');
        });
        // Müşteri organizasyonu açma — personel (user.manage global). Tenant
        // middleware'i yok: açılacak organizasyon henüz mevcut değil.
        Route::get('/yeni-musteri', [OnboardingController::class, 'create'])
            ->middleware('permission:user.manage')
            ->name('onboarding.create');
        Route::post('/yeni-musteri', [OnboardingController::class, 'store'])
            ->middleware(['permission:user.manage', 'throttle:invite'])
            ->name('onboarding.store');

        // --- CMS (faz 9) — varsayılan website, personel, tenant context'siz ----
        // Her durum geçişi kendi izniyle (bkz. ContentController başlığı).
        // Liste/detay: content.* taşıyan HERKES görür — matriste operations_admin
        // content.edit/review/schedule taşır ama content.view taşımaz; okumadan
        // düzenleme olmaz, görme yetkisi eylem yetkisinden türer.
        $canSee = 'permission:content.view|content.create|content.edit|content.review|content.approve|content.publish|content.schedule|content.archive';

        Route::prefix('icerik')->name('content.')->group(function () use ($canSee) {
            Route::get('/', [ContentController::class, 'index'])->middleware($canSee)->name('index');
            Route::get('/yeni', [ContentController::class, 'create'])->middleware('permission:content.create')->name('create');
            Route::post('/', [ContentController::class, 'store'])->middleware('permission:content.create')->name('store');
            // Takvim (faz 24): /{content}'ten ÖNCE — aksi halde 'takvim' model anahtarı sanılır.
            Route::get('/takvim', [ContentController::class, 'calendar'])->middleware($canSee)->name('calendar');
            Route::get('/menu', [ContentController::class, 'menu'])->middleware('permission:content.edit')->name('menu');
            Route::put('/menu/{website}', [ContentController::class, 'saveMenu'])->middleware('permission:content.edit')->name('menu.update');
            Route::put('/tema/{website}', [ContentController::class, 'saveTheme'])->middleware('permission:content.edit')->name('theme');
            Route::put('/baglantilar/{website}', [ContentController::class, 'saveLinks'])->middleware('permission:content.edit')->name('links');
            // Medya kütüphanesi (faz 30): yükle/alt metin content.edit; sil content.publish. ?website= seçimi.
            Route::get('/medya', [MediaController::class, 'index'])->middleware('permission:content.edit|content.publish')->name('media.index');
            Route::post('/medya', [MediaController::class, 'store'])->middleware('permission:content.edit')->name('media.store');
            Route::put('/medya/{media}', [MediaController::class, 'update'])->middleware('permission:content.edit')->name('media.update');
            Route::delete('/medya/{media}', [MediaController::class, 'destroy'])->middleware('permission:content.publish')->name('media.destroy');

            // Sayfa kurucu (faz 35): taslak content.edit, yayın/geri alma content.publish. {section} int, siteye süzülür.
            Route::prefix('tasarim')->name('builder.')->where(['section' => '[0-9]+', 'revision' => '[0-9]+'])->group(function () {
                Route::get('/', [SiteBuilderController::class, 'index'])->middleware('permission:content.edit|content.publish')->name('index');
                Route::post('/{website}/bolum', [SiteBuilderController::class, 'store'])->middleware('permission:content.edit')->name('store');
                Route::put('/{website}/bolum/{section}', [SiteBuilderController::class, 'update'])->middleware('permission:content.edit')->name('update');
                Route::post('/{website}/bolum/{section}/tasi', [SiteBuilderController::class, 'move'])->middleware('permission:content.edit')->name('move');
                Route::post('/{website}/sirala', [SiteBuilderController::class, 'reorder'])->middleware('permission:content.edit')->name('reorder');
                Route::post('/{website}/bolum/{section}/cogalt', [SiteBuilderController::class, 'duplicate'])->middleware('permission:content.edit')->name('duplicate');
                Route::post('/{website}/bolum/{section}/gorunurluk', [SiteBuilderController::class, 'toggle'])->middleware('permission:content.edit')->name('toggle');
                Route::delete('/{website}/bolum/{section}', [SiteBuilderController::class, 'destroy'])->middleware('permission:content.edit')->name('destroy');
                Route::post('/{website}/yayinla', [SiteBuilderController::class, 'publish'])->middleware('permission:content.publish')->name('publish');
                Route::post('/{website}/geri-al/{revision}', [SiteBuilderController::class, 'rollback'])->middleware('permission:content.publish')->name('rollback');
            });

            // Vitrin blokları (faz 10): doğrudan canlıya çıkar -> content.publish.
            Route::get('/bloklar', [SiteBlockController::class, 'index'])->middleware('permission:content.publish')->name('blocks');
            Route::put('/bloklar/metinler', [SiteBlockController::class, 'updateTexts'])->middleware('permission:content.publish')->name('blocks.texts');
            Route::put('/bloklar/{block}', [SiteBlockController::class, 'update'])->where('block', '[a-z_]+')->middleware('permission:content.publish')->name('blocks.update');
            Route::get('/{content}', [ContentController::class, 'show'])->middleware($canSee)->name('show');
            Route::get('/{content}/duzenle', [ContentController::class, 'edit'])->middleware('permission:content.edit')->name('edit');
            Route::put('/{content}', [ContentController::class, 'update'])->middleware('permission:content.edit')->name('update');
            Route::delete('/{content}', [ContentController::class, 'destroy'])->middleware('permission:content.archive')->name('destroy');

            Route::post('/{content}/incelemeye-gonder', [ContentController::class, 'submit'])->middleware('permission:content.edit')->name('submit');
            Route::post('/{content}/geri-gonder', [ContentController::class, 'reject'])->middleware('permission:content.review')->name('reject');
            Route::post('/{content}/onayla', [ContentController::class, 'approve'])->middleware('permission:content.approve')->name('approve');
            Route::post('/{content}/yayinla', [ContentController::class, 'publish'])->middleware('permission:content.publish')->name('publish');
            Route::post('/{content}/yayindan-kaldir', [ContentController::class, 'unpublish'])->middleware('permission:content.publish')->name('unpublish');
            Route::post('/{content}/zamanla', [ContentController::class, 'schedule'])->middleware('permission:content.schedule')->name('schedule');
            Route::post('/{content}/arsivle', [ContentController::class, 'archive'])->middleware('permission:content.archive')->name('archive');
            Route::post('/{content}/taslaga-al', [ContentController::class, 'restore'])->middleware('permission:content.edit')->name('restore');

            // Çalışma taslağı (faz 18): yayındaki içeriği düşürmeden düzenle; aynı akış, yayın = birleştirme.
            Route::prefix('/{content}/taslak')->name('draft.')->group(function () {
                Route::post('/', [ContentDraftController::class, 'open'])->middleware('permission:content.edit')->name('open');
                Route::get('/duzenle', [ContentDraftController::class, 'edit'])->middleware('permission:content.edit')->name('edit');
                Route::put('/', [ContentDraftController::class, 'update'])->middleware('permission:content.edit')->name('update');
                Route::post('/incelemeye-gonder', [ContentDraftController::class, 'submit'])->middleware('permission:content.edit')->name('submit');
                Route::post('/geri-gonder', [ContentDraftController::class, 'reject'])->middleware('permission:content.review')->name('reject');
                Route::post('/onayla', [ContentDraftController::class, 'approve'])->middleware('permission:content.approve')->name('approve');
                Route::post('/yayinla', [ContentDraftController::class, 'publish'])->middleware('permission:content.publish')->name('publish');
                Route::post('/zamanla', [ContentDraftController::class, 'schedule'])->middleware('permission:content.schedule')->name('schedule');
                Route::post('/taslaga-al', [ContentDraftController::class, 'restore'])->middleware('permission:content.edit|content.schedule')->name('restore');
                Route::delete('/', [ContentDraftController::class, 'discard'])->middleware('permission:content.edit')->name('discard');
            });
        });

        // --- Websiteler (faz 10) — personel, tenant context'siz ----------------
        Route::prefix('websiteler')->name('websites.')->group(function () {
            Route::get('/', [WebsiteController::class, 'index'])->middleware('permission:website.view|website.manage')->name('index');
            Route::get('/yeni', [WebsiteController::class, 'create'])->middleware('permission:website.manage')->name('create');
            Route::post('/', [WebsiteController::class, 'store'])->middleware('permission:website.manage')->name('store');
            Route::get('/{website}/duzenle', [WebsiteController::class, 'edit'])->middleware('permission:website.manage')->name('edit');
            Route::put('/{website}', [WebsiteController::class, 'update'])->middleware('permission:website.manage')->name('update');
            Route::put('/{website}/ayarlar', [WebsiteController::class, 'settings'])->middleware('permission:website.manage')->name('settings');
            Route::delete('/{website}', [WebsiteController::class, 'destroy'])->middleware('permission:website.manage')->name('destroy');
            Route::put('/{website}/hero', [MediaController::class, 'hero'])->middleware('permission:website.manage')->name('hero');
        });

        // --- Önbellek (faz 12-14) — personel, tenant context'siz -----------------
        // Geçersizleme JIT ister (matris: "global purge yıkıcı"); kaynak = website id,
        // global purge için sabit 0. JIT talebi cache.view kapısından açılır.
        Route::prefix('onbellek')->name('cache.')->group(function () {
            Route::get('/', [CacheController::class, 'index'])->middleware('permission:cache.view')->name('index');
            Route::post('/{website}/isit', [CacheController::class, 'warm'])->middleware('permission:cache.warm')->name('warm');
            Route::get('/{website}/anahtarlar', [CacheController::class, 'inspect'])->middleware('permission:cache.inspect')->name('inspect');
            Route::put('/{website}/ayarlar', [CacheController::class, 'settings'])
                ->middleware('permission:cache.settings,,cache_settings,website')->name('settings');
            Route::post('/{website}/gecersiz-kil', [CacheController::class, 'purge'])
                ->middleware('permission:cache.invalidate,,cache,website')->name('purge');
            Route::post('/tumu/bosalt', [CacheController::class, 'purgeAll'])
                ->middleware('permission:cache.invalidate,,cache,=0')->name('purge-all');
            Route::post('/{website}/jit', [CacheController::class, 'requestJit'])
                ->where('website', '[0-9]+')->middleware(['permission:cache.view', 'throttle:jit-request'])->name('jit');
        });

        // --- SEO (faz 15) — personel, tenant context'siz ----------------------
        // Ayar değişikliği JIT ister (matris: robots/canonical tüm siteyi deindeksleyebilir).
        Route::prefix('seo')->name('seo.')->group(function () {
            Route::get('/', [SeoController::class, 'index'])->middleware('permission:seo.view')->name('index');
            Route::get('/{website}/denetim', [SeoController::class, 'audit'])->middleware('permission:seo.audit')->name('audit');
            Route::put('/{website}/ayarlar', [SeoController::class, 'settings'])
                ->middleware('permission:seo.settings,,seo_settings,website')->name('settings');
            Route::post('/{website}/jit', [SeoController::class, 'requestJit'])
                ->middleware(['permission:seo.view', 'throttle:jit-request'])->name('jit');
        });

        // --- GEO / Entity (faz 16-17) — personel, tenant context'siz -----------
        Route::prefix('geo')->name('geo.')->group(function () {
            Route::get('/', [GeoController::class, 'index'])->middleware('permission:geo.view')->name('index');
            Route::get('/lokasyon/{location}', [GeoController::class, 'edit'])->middleware('permission:geo.edit')->name('edit');
            Route::get('/lokasyon-yeni', [GeoController::class, 'create'])->middleware('permission:geo.edit')->name('create');
            Route::post('/lokasyon', [GeoController::class, 'store'])->middleware('permission:geo.edit')->name('store');
            Route::put('/lokasyon/{location}/kunye', [GeoController::class, 'updateBasics'])->middleware('permission:geo.edit')->name('basics');
            Route::delete('/lokasyon/{location}', [GeoController::class, 'destroy'])->middleware('permission:geo.publish')->name('destroy');
            Route::put('/lokasyon/{location}/yayin', [GeoController::class, 'publish'])->middleware('permission:geo.publish')->name('publish');
            // Lokasyon görselleri (faz 3): geo.edit; {link} int, lokasyona süzülür. Yükleme karantina zincirinden geçer.
            Route::prefix('/lokasyon/{location}/gorseller')->name('media.')->where(['link' => '[0-9]+'])->middleware('permission:geo.edit')->group(function () {
                Route::get('/', [LocationMediaController::class, 'index'])->name('index');
                Route::post('/', [LocationMediaController::class, 'store'])->middleware('throttle:media-upload')->name('store');
                Route::post('/sirala', [LocationMediaController::class, 'reorder'])->name('reorder');
                Route::put('/{link}', [LocationMediaController::class, 'update'])->name('update');
                Route::post('/{link}/kapak', [LocationMediaController::class, 'cover'])->name('cover');
                Route::post('/{link}/birincil', [LocationMediaController::class, 'primary'])->name('primary');
                Route::post('/{link}/tasi', [LocationMediaController::class, 'move'])->name('move');
                Route::post('/{link}/degistir', [LocationMediaController::class, 'replace'])->middleware('throttle:media-upload')->name('replace');
                Route::delete('/{link}', [LocationMediaController::class, 'destroy'])->name('destroy');
            });
            // Odalar (booking v1): lokasyon künyesinin parçası; {room} int, lokasyona süzülür (RoomController::roomOf).
            Route::get('/lokasyon/{location}/odalar', [RoomController::class, 'index'])->middleware('permission:geo.edit')->name('rooms.index');
            Route::post('/lokasyon/{location}/odalar', [RoomController::class, 'store'])->middleware('permission:geo.edit')->name('rooms.store');
            Route::put('/lokasyon/{location}/odalar/{room}', [RoomController::class, 'update'])->where('room', '[0-9]+')->middleware('permission:geo.edit')->name('rooms.update');
            Route::delete('/lokasyon/{location}/odalar/{room}', [RoomController::class, 'destroy'])->where('room', '[0-9]+')->middleware('permission:geo.edit')->name('rooms.destroy');
            Route::put('/lokasyon/{location}', [GeoController::class, 'update'])->middleware('permission:geo.edit')->name('update');
            Route::put('/{website}/varlik', [GeoController::class, 'entity'])
                ->middleware('permission:geo.settings,,geo_entity,website')->name('entity');
            Route::post('/{website}/jit', [GeoController::class, 'requestJit'])
                ->middleware(['permission:geo.view', 'throttle:jit-request'])->name('jit');
        });

        // --- Tenant context'li ekranlar -----------------------------------
        Route::middleware('tenant')->group(function () {

            Route::get('/', DashboardController::class)->name('dashboard');

            // Organizasyon künyesi (organization.manage, organization kapsamı; owner).
            Route::put('/organizasyon/kunye', [ContextController::class, 'updateOrganization'])
                ->middleware('permission:organization.manage')
                ->name('organization.update');

            // Şirketler — liste servis tarafından süzülür; oluşturma organizasyon
            // yönetimi ister; görüntüleme şirket kapsamlı company.view ister.
            Route::get('/sirketler', [CompanyController::class, 'index'])->name('companies.index');
            Route::get('/sirketler/yeni', [CompanyController::class, 'create'])
                ->middleware('permission:organization.manage')
                ->name('companies.create');
            Route::post('/sirketler', [CompanyController::class, 'store'])
                ->middleware('permission:organization.manage')
                ->name('companies.store');
            Route::get('/sirketler/{company}', [CompanyController::class, 'show'])
                ->middleware('permission:company.view|kyc.view_status,company')
                ->name('companies.show');
            Route::put('/sirketler/{company}', [CompanyController::class, 'update'])
                ->middleware('permission:company.update,company')
                ->name('companies.update');

            // KYC — matristeki üçlü ayrımın route karşılığı (bkz. KycController).
            Route::get('/sirketler/{company}/kyc', [KycController::class, 'show'])
                ->middleware('permission:kyc.view|kyc.view_status,company')
                ->name('companies.kyc.show');
            Route::post('/sirketler/{company}/kyc', [KycController::class, 'upload'])
                ->middleware(['permission:kyc.upload,company', 'throttle:kyc-upload'])
                ->name('companies.kyc.upload');

            // Belge İÇERİĞİ: müşteri kyc.view ile JIT'siz, personel kyc.view_document
            // ile yalnızca açık JIT grant'i varsa. scopeBindings: {kycDocument},
            // {company}->kycDocuments() ilişkisi üzerinden çözülür (kardeş şirket savunması).
            Route::get('/sirketler/{company}/kyc/{kycDocument}/indir', [KycController::class, 'download'])
                ->middleware('permission:kyc.view|kyc.view_document,company,kyc_document,kycDocument')
                ->scopeBindings()
                ->name('companies.kyc.download');

            // JIT talebi: rolünde kyc.view_document olan personel, kyc.view_status
            // kapısından geçip grant açar. Grant'in kendisi allows() ile değil
            // can() ile doğrulanır — aksi halde kimse ilk grant'i açamazdı.
            Route::post('/sirketler/{company}/kyc/{kycDocument}/jit', [KycController::class, 'requestJit'])
                ->middleware(['permission:kyc.view_status,company', 'throttle:jit-request'])
                ->scopeBindings()
                ->name('companies.kyc.jit');

            // İnceleme kararları — her karar kendi iznini ister.
            Route::post('/sirketler/{company}/kyc/{kycDocument}/onayla', [KycController::class, 'approve'])
                ->middleware('permission:kyc.approve,company')
                ->scopeBindings()
                ->name('companies.kyc.approve');
            Route::post('/sirketler/{company}/kyc/{kycDocument}/reddet', [KycController::class, 'reject'])
                ->middleware('permission:kyc.reject,company')
                ->scopeBindings()
                ->name('companies.kyc.reject');
            Route::post('/sirketler/{company}/kyc/{kycDocument}/ek-bilgi', [KycController::class, 'moreInfo'])
                ->middleware('permission:kyc.request_more_info,company')
                ->scopeBindings()
                ->name('companies.kyc.more-info');

            // Üyelikler — şirket sahibi / yasal temsilci (membership.manage, company).
            Route::get('/sirketler/{company}/uyeler', [MembershipController::class, 'index'])
                ->middleware('permission:membership.manage,company')
                ->name('companies.members.index');
            Route::post('/sirketler/{company}/uyeler', [MembershipController::class, 'store'])
                ->middleware(['permission:membership.manage,company', 'throttle:invite'])
                ->name('companies.members.store');
            Route::post('/sirketler/{company}/uyeler/{userRole}/askiya-al', [MembershipController::class, 'suspend'])
                ->middleware('permission:membership.manage,company')
                ->scopeBindings()
                ->name('companies.members.suspend');
            Route::post('/sirketler/{company}/uyeler/{userRole}/etkinlestir', [MembershipController::class, 'reactivate'])
                ->middleware('permission:membership.manage,company')
                ->scopeBindings()
                ->name('companies.members.reactivate');

            // Rezervasyonlar (booking v1) — şirket kapsamı; matris: owner/company_admin/employee
            // view+create, iptal owner/company_admin. {booking} scopeBindings: Company::bookings().
            Route::get('/sirketler/{company}/rezervasyonlar', [BookingController::class, 'index'])
                ->middleware('permission:booking.view,company')
                ->name('companies.bookings.index');
            Route::get('/sirketler/{company}/rezervasyonlar/yeni', [BookingController::class, 'create'])
                ->middleware('permission:booking.create,company')
                ->name('companies.bookings.create');
            Route::post('/sirketler/{company}/rezervasyonlar', [BookingController::class, 'store'])
                ->middleware(['permission:booking.create,company', 'throttle:booking'])
                ->name('companies.bookings.store');
            Route::post('/sirketler/{company}/rezervasyonlar/{booking}/iptal', [BookingController::class, 'cancel'])
                ->middleware('permission:booking.cancel,company')
                ->scopeBindings()
                ->name('companies.bookings.cancel');

            // Personel kuyruğu — aktif organizasyon içinde.
            // Müşteri sitesi (faz 10) — organizasyonun web sitesi, şirket kapsamlı content.*.
            // Matris: owner edit/review/schedule, company_admin edit; yayın = zamanlama.
            // {content} int'tir (model binding yok): SiteController organizasyona süzerek çözer, aksi 404.
            Route::prefix('/sirketler/{company}/site')->name('companies.site.')->where(['content' => '[0-9]+'])->group(function () {
                $canSee = 'permission:content.edit|content.review|content.schedule,company';

                Route::get('/', [SiteController::class, 'index'])->middleware($canSee)->name('index');

                // SEO (faz 15, müşteri): {website} int, organizasyona süzülür.
                Route::get('/menu/{website}', [SiteController::class, 'menu'])->where('website', '[0-9]+')->middleware('permission:content.edit,company')->name('menu');
                Route::put('/menu/{website}', [SiteController::class, 'saveMenu'])->where('website', '[0-9]+')->middleware('permission:content.edit,company')->name('menu.update');
                Route::put('/tema/{website}', [SiteController::class, 'saveTheme'])->where('website', '[0-9]+')->middleware('permission:content.edit,company')->name('theme');
                Route::put('/ayarlar/{website}', [SiteController::class, 'saveSettings'])->where('website', '[0-9]+')->middleware('permission:content.edit,company')->name('settings');
                Route::put('/baglantilar/{website}', [SiteController::class, 'saveLinks'])->where('website', '[0-9]+')->middleware('permission:content.edit,company')->name('links');
                Route::get('/seo', [SiteSeoController::class, 'index'])->middleware('permission:seo.view,company')->name('seo.index');
                Route::put('/seo/{website}', [SiteSeoController::class, 'update'])->where('website', '[0-9]+')->middleware('permission:seo.edit,company')->name('seo.update');
                Route::put('/seo/{website}/indeksleme', [SiteSeoController::class, 'indexing'])->where('website', '[0-9]+')->middleware('permission:seo.publish,company')->name('seo.indexing');
                Route::get('/{content}', [SiteController::class, 'show'])->middleware($canSee)->name('show');
                Route::get('/{content}/duzenle', [SiteController::class, 'edit'])->middleware('permission:content.edit,company')->name('edit');
                Route::put('/{content}', [SiteController::class, 'update'])->middleware('permission:content.edit,company')->name('update');
                Route::post('/{content}/incelemeye-gonder', [SiteController::class, 'submit'])->middleware('permission:content.edit,company')->name('submit');
                Route::post('/{content}/geri-gonder', [SiteController::class, 'reject'])->middleware('permission:content.review,company')->name('reject');
                Route::post('/{content}/zamanla', [SiteController::class, 'schedule'])->middleware('permission:content.schedule,company')->name('schedule');
                Route::post('/{content}/taslaga-al', [SiteController::class, 'restore'])->middleware('permission:content.edit|content.schedule,company')->name('restore');

                Route::prefix('/{content}/taslak')->name('draft.')->group(function () {
                    Route::post('/', [SiteController::class, 'openDraft'])->middleware('permission:content.edit,company')->name('open');
                    Route::get('/duzenle', [SiteController::class, 'editDraft'])->middleware('permission:content.edit,company')->name('edit');
                    Route::put('/', [SiteController::class, 'updateDraft'])->middleware('permission:content.edit,company')->name('update');
                    Route::post('/incelemeye-gonder', [SiteController::class, 'submitDraft'])->middleware('permission:content.edit,company')->name('submit');
                    Route::post('/geri-gonder', [SiteController::class, 'rejectDraft'])->middleware('permission:content.review,company')->name('reject');
                    Route::post('/zamanla', [SiteController::class, 'scheduleDraft'])->middleware('permission:content.schedule,company')->name('schedule');
                    Route::post('/taslaga-al', [SiteController::class, 'restoreDraft'])->middleware('permission:content.edit|content.schedule,company')->name('restore');
                    Route::delete('/', [SiteController::class, 'discardDraft'])->middleware('permission:content.edit,company')->name('discard');
                });
            });
            Route::get('/kyc-kuyrugu', [KycController::class, 'queue'])
                ->middleware('permission:kyc.view_status')
                ->name('kyc.queue');
        });
    });
});

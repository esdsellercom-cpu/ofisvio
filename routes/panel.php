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
use App\Http\Controllers\Panel\CacheController;
use App\Http\Controllers\Panel\CompanyController;
use App\Http\Controllers\Panel\ContentController;
use App\Http\Controllers\Panel\ContentDraftController;
use App\Http\Controllers\Panel\ContextController;
use App\Http\Controllers\Panel\DashboardController;
use App\Http\Controllers\Panel\GeoController;
use App\Http\Controllers\Panel\KycController;
use App\Http\Controllers\Panel\MembershipController;
use App\Http\Controllers\Panel\OnboardingController;
use App\Http\Controllers\Panel\SeoController;
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
            Route::get('/{content}', [ContentController::class, 'show'])->middleware($canSee)->name('show');
            Route::get('/{content}/duzenle', [ContentController::class, 'edit'])->middleware('permission:content.edit')->name('edit');
            Route::put('/{content}', [ContentController::class, 'update'])->middleware('permission:content.edit')->name('update');

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
        });

        // --- Önbellek (faz 12-14) — personel, tenant context'siz -----------------
        // Geçersizleme JIT ister (matris: "global purge yıkıcı"); kaynak = website id,
        // global purge için sabit 0. JIT talebi cache.view kapısından açılır.
        Route::prefix('onbellek')->name('cache.')->group(function () {
            Route::get('/', [CacheController::class, 'index'])->middleware('permission:cache.view')->name('index');
            Route::post('/{website}/isit', [CacheController::class, 'warm'])->middleware('permission:cache.warm')->name('warm');
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
            Route::put('/lokasyon/{location}', [GeoController::class, 'update'])->middleware('permission:geo.edit')->name('update');
            Route::put('/{website}/varlik', [GeoController::class, 'entity'])
                ->middleware('permission:geo.settings,,geo_entity,website')->name('entity');
            Route::post('/{website}/jit', [GeoController::class, 'requestJit'])
                ->middleware(['permission:geo.view', 'throttle:jit-request'])->name('jit');
        });

        // --- Tenant context'li ekranlar -----------------------------------
        Route::middleware('tenant')->group(function () {

            Route::get('/', DashboardController::class)->name('dashboard');

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

            // Personel kuyruğu — aktif organizasyon içinde.
            Route::get('/kyc-kuyrugu', [KycController::class, 'queue'])
                ->middleware('permission:kyc.view_status')
                ->name('kyc.queue');
        });
    });
});

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
use App\Http\Controllers\Panel\CompanyController;
use App\Http\Controllers\Panel\ContentController;
use App\Http\Controllers\Panel\ContextController;
use App\Http\Controllers\Panel\DashboardController;
use App\Http\Controllers\Panel\KycController;
use App\Http\Controllers\Panel\MembershipController;
use App\Http\Controllers\Panel\OnboardingController;
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

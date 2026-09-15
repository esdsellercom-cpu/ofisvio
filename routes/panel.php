<?php

/**
 * Panel route'ları — zincir: auth -> tenant -> permission.
 *
 * Yetki BURADA verilir, controller'da değil (bkz. routes/BOOTSTRAP.md).
 * permission:<izin>[,<kapsam>[,<kaynak tipi>,<kaynak parametresi>]]
 *
 * İki grup:
 *   1) auth + tenant YOK   : organizasyon seçimi, müşteri açılışı — context
 *                            olmadan çalışmak ZORUNDA olan ekranlar.
 *   2) auth + tenant       : geri kalan her şey.
 */

use App\Http\Controllers\Panel\CompanyController;
use App\Http\Controllers\Panel\ContextController;
use App\Http\Controllers\Panel\DashboardController;
use App\Http\Controllers\Panel\KycController;
use App\Http\Controllers\Panel\OnboardingController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->prefix('panel')->name('panel.')->group(function () {

    // --- Context'siz ekranlar -------------------------------------------
    Route::get('/organizasyon', [ContextController::class, 'select'])->name('context.select');
    Route::post('/organizasyon', [ContextController::class, 'switch'])->name('context.switch');

    // Müşteri organizasyonu açma — personel (user.manage global). Tenant
    // middleware'i yok: açılacak organizasyon henüz mevcut değil.
    Route::get('/yeni-musteri', [OnboardingController::class, 'create'])
        ->middleware('permission:user.manage')
        ->name('onboarding.create');
    Route::post('/yeni-musteri', [OnboardingController::class, 'store'])
        ->middleware('permission:user.manage')
        ->name('onboarding.store');

    // --- Tenant context'li ekranlar ---------------------------------------
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
            ->middleware('permission:kyc.upload,company')
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
            ->middleware('permission:kyc.view_status,company')
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

        // Personel kuyruğu — aktif organizasyon içinde.
        Route::get('/kyc-kuyrugu', [KycController::class, 'queue'])
            ->middleware('permission:kyc.view_status')
            ->name('kyc.queue');
    });
});

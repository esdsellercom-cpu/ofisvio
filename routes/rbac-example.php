<?php

/**
 * ÖRNEK route tanımları. KYC bloğu gerçek modülün kalıbıdır — yeni modüller
 * bunu kopyalayarak yazılmalıdır.
 *
 * Middleware sırası zincirin kendisidir ve bozulmamalıdır:
 *   auth          -> Authentication
 *   tenant        -> Active Organization Context + Membership Check
 *   permission:*  -> Company Context + Policy (+ gerekiyorsa JIT)
 *
 * permission middleware imzası:
 *   permission:<izin>[,<kapsam parametresi>[,<kaynak tipi>,<kaynak parametresi>]]
 */

use App\Http\Controllers\KycController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'tenant'])->group(function () {

    // ---------------------------------------------------------------
    // KYC — matristeki üçlü ayrımın route karşılığı
    // ---------------------------------------------------------------

    // Durum + metadata. Müşteri kyc.view ile girer; personel kyc.view_status
    // ile girer. İkisi de JIT gerektirmez, ikisi de belge İÇERİĞİ görmez.
    Route::get('/companies/{company}/kyc', [KycController::class, 'index'])
        ->middleware('permission:kyc.view,company');

    Route::post('/companies/{company}/kyc', [KycController::class, 'store'])
        ->middleware('permission:kyc.upload,company');

    // Belge İÇERİĞİ — iki ayrı yol, tek route.
    //   kyc.view          : müşteri kendi belgesini JIT'siz açar (company kapsamı)
    //   kyc.view_document : personel, açık bir JIT grant'i varsa açar (global)
    // `|` alternatif izin demektir: herhangi biri geçerse route açılır. Tek
    // izin yazmak müşteriyi kendi belgesinden kilitlerdi.
    //
    // ->scopeBindings() ZORUNLU: {document}, {company}'nin ilişkisi üzerinden
    // çözülür. Olmazsa aynı organizasyondaki kardeş şirketin belgesi, kendi
    // şirketinin id'si ile açılabilir (TenantScope organizasyon sınırını
    // çizer, şirket sınırını değil).
    Route::get('/companies/{company}/kyc/{document}', [KycController::class, 'show'])
        ->middleware('permission:kyc.view|kyc.view_document,company,kyc_document,document')
        ->scopeBindings();

    // İnceleme kararı — global kapsamlı personel izni.
    Route::post('/companies/{company}/kyc/{document}/review', [KycController::class, 'review'])
        ->middleware('permission:kyc.approve,company')
        ->scopeBindings();

    // ---------------------------------------------------------------
    // Diğer kalıplar
    // ---------------------------------------------------------------

    // Lokasyon kapsamlı: resepsiyon yalnızca kendi lokasyonunu görür.
    Route::get('/locations/{location}/visitors', [/* VisitorController::class */ 'index'])
        ->middleware('permission:visitor.view,location');

    // Global kapsamlı: kapsam parametresi yok, karar yalnızca global rolle verilir.
    Route::get('/admin/panel', [/* AdminController::class */ 'index'])
        ->middleware('permission:admin.panel.access');

    // Context değiştirme — kendisi permission istemez, üyelik/personel
    // kontrolü ContextSwitchService içindedir.
    Route::post('/context/switch', [/* ContextController::class */ 'switch']);
});

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
use App\Http\Controllers\Panel\CollectionController;
use App\Http\Controllers\Panel\CompanyController;
use App\Http\Controllers\Panel\CompanyInvoiceController;
use App\Http\Controllers\Panel\CompanySpaceController;
use App\Http\Controllers\Panel\CompanySubscriptionController;
use App\Http\Controllers\Panel\ContentController;
use App\Http\Controllers\Panel\ContentDraftController;
use App\Http\Controllers\Panel\ContextController;
use App\Http\Controllers\Panel\DashboardController;
use App\Http\Controllers\Panel\EventController;
use App\Http\Controllers\Panel\FranchiseController;
use App\Http\Controllers\Panel\GeoController;
use App\Http\Controllers\Panel\IntegrationController;
use App\Http\Controllers\Panel\InventoryController;
use App\Http\Controllers\Panel\InvoiceController;
use App\Http\Controllers\Panel\KycController;
use App\Http\Controllers\Panel\LandingController;
use App\Http\Controllers\Panel\LeadController;
use App\Http\Controllers\Panel\LiveEditController;
use App\Http\Controllers\Panel\LocationMediaController;
use App\Http\Controllers\Panel\LocationSpaceController;
use App\Http\Controllers\Panel\MediaController;
use App\Http\Controllers\Panel\MemberCenterController;
use App\Http\Controllers\Panel\MembershipController;
use App\Http\Controllers\Panel\NotificationController;
use App\Http\Controllers\Panel\OnboardingController;
use App\Http\Controllers\Panel\OperationsDashboardController;
use App\Http\Controllers\Panel\PerformanceController;
use App\Http\Controllers\Panel\PlanController;
use App\Http\Controllers\Panel\RedirectController;
use App\Http\Controllers\Panel\ReportController;
use App\Http\Controllers\Panel\RoomController;
use App\Http\Controllers\Panel\SearchController;
use App\Http\Controllers\Panel\SeoCenterController;
use App\Http\Controllers\Panel\SeoController;
use App\Http\Controllers\Panel\SeoSettingsController;
use App\Http\Controllers\Panel\ServiceController;
use App\Http\Controllers\Panel\SettingsController;
use App\Http\Controllers\Panel\SiteBlockController;
use App\Http\Controllers\Panel\SiteBuilderController;
use App\Http\Controllers\Panel\SiteController;
use App\Http\Controllers\Panel\SiteSeoController;
use App\Http\Controllers\Panel\SpaceController;
use App\Http\Controllers\Panel\SubscriptionController;
use App\Http\Controllers\Panel\SystemController;
use App\Http\Controllers\Panel\UserController;
use App\Http\Controllers\Panel\WebsiteController;
use Illuminate\Support\Facades\Route;

// 'verified' (audit S-4): e-postası doğrulanmamış hesap panele giremez; Fortify doğrulama ekranına yönlendirir.
// 'account.active': askıya alınmış hesap açık oturumla da giremez (AccountSecurityService).
Route::middleware(['auth', 'account.active', 'verified'])->prefix('panel')->name('panel.')->group(function () {

    // --- Hesap — 2FA zorunluluğunun DIŞINDA: kurulumun yapıldığı yer -------
    // Profil/şifre formları Fortify route'larına gider. Güvenlik sayfası
    // password.confirm ister; Fortify'ın 2FA POST'ları da aynı onayı kullanır.
    Route::get('/hesap', [AccountController::class, 'show'])->name('account');
    Route::post('/hesap/tema', [AccountController::class, 'theme'])->name('account.theme');
    // Oturum yönetimi (audit): diğer cihazlardan çıkış şifre onayı ister.
    Route::post('/hesap/oturumlar/kapat', [AccountController::class, 'logoutOtherDevices'])->middleware('password.confirm')->name('account.sessions.close');
    Route::get('/hesap/guvenlik', [AccountController::class, 'security'])
        ->middleware('password.confirm')
        ->name('account.security');

    // --- Geri kalan her şey: personel için doğrulanmış 2FA şart -------------
    Route::middleware('staff.2fa')->group(function () {

        // --- Context'siz ekranlar ---------------------------------------
        Route::get('/organizasyon', [ContextController::class, 'select'])->name('context.select');
        Route::post('/organizasyon', [ContextController::class, 'switch'])->middleware('throttle:context-switch')->name('context.switch');

        // Operasyon paneli (audit P1-13): personel özeti, tenant bağlamsız; bloklar izne göre (controller). Giriş hedefi /panel/baslangic.
        Route::get('/operasyon', OperationsDashboardController::class)->middleware('permission:booking.view|invoice.view|subscription.view|space.view|lead.view|event.view|franchise.view|kyc.view_status|notification.view|geo.view')->name('operations');
        Route::get('/baslangic', LandingController::class)->name('landing');

        // Üst çubuk araması (faz 38): kümeler izne göre serviste süzülür; ek route izni yok.
        Route::get('/ara', SearchController::class)->name('search');

        // Faz 39 — artifact menü paritesi: alanlar (§3), raporlar (§16), entegrasyonlar (§18).
        // Masalar, ofisler & odalar (audit P0-2): envanter/doluluk space.view (ya da geo/booking görüntüleme); tahsis space.manage.
        // 'anylocation': lokasyon yöneticisi/resepsiyon yalnız kendi lokasyonlarını görür (controller süzer, yabancı alan 404).
        Route::get('/alanlar', [SpaceController::class, 'index'])->middleware('permission:space.view|geo.view|booking.view,anylocation')->name('spaces.index');
        Route::get('/alanlar/{space}', [SpaceController::class, 'show'])->where('space', '[0-9]+')->middleware('permission:space.view|space.manage,anylocation')->name('spaces.show');
        Route::post('/alanlar/{space}/tahsis', [SpaceController::class, 'assign'])->where('space', '[0-9]+')->middleware('permission:space.manage,anylocation')->name('spaces.assign');
        Route::post('/alanlar/{space}/tahsis/{assignment}/bitir', [SpaceController::class, 'end'])->where(['space' => '[0-9]+', 'assignment' => '[0-9]+'])->middleware('permission:space.manage,anylocation')->name('spaces.end');
        // Envanter ekranı yazma işlemleri (faz 46): tek ekrandan envanter/tahsis/demirbaş. Oda yazımı geo.edit (global).
        Route::prefix('alanlar')->name('spaces.')->group(function () {
            Route::post('/envanter/alan', [InventoryController::class, 'storeSpace'])->middleware('permission:space.manage,anylocation')->name('inventory.space.store');
            Route::put('/envanter/alan/{space}', [InventoryController::class, 'updateSpace'])->where('space', '[0-9]+')->middleware('permission:space.manage,anylocation')->name('inventory.space.update');
            Route::post('/envanter/oda', [InventoryController::class, 'storeRoom'])->middleware('permission:geo.edit')->name('inventory.room.store');
            Route::put('/envanter/oda/{room}', [InventoryController::class, 'updateRoom'])->where('room', '[0-9]+')->middleware('permission:geo.edit')->name('inventory.room.update');
            Route::post('/tahsis', [InventoryController::class, 'quickAssign'])->middleware('permission:space.manage,anylocation')->name('inventory.assign');
            Route::put('/tahsis/{assignment}', [InventoryController::class, 'updateAssignment'])->where('assignment', '[0-9]+')->middleware('permission:space.manage,anylocation')->name('inventory.assignment.update');
            Route::post('/tahsis/{assignment}/bitir', [InventoryController::class, 'endAssignment'])->where('assignment', '[0-9]+')->middleware('permission:space.manage,anylocation')->name('inventory.assignment.end');
            Route::post('/demirbas', [InventoryController::class, 'storeAsset'])->middleware('permission:space.manage,anylocation')->name('inventory.asset.store');
            Route::put('/demirbas/{asset}', [InventoryController::class, 'updateAsset'])->where('asset', '[0-9]+')->middleware('permission:space.manage,anylocation')->name('inventory.asset.update');
            Route::delete('/demirbas/{asset}', [InventoryController::class, 'destroyAsset'])->where('asset', '[0-9]+')->middleware('permission:space.manage,anylocation')->name('inventory.asset.destroy');
        });
        Route::get('/raporlar', [ReportController::class, 'index'])->middleware('permission:analytics.view')->name('reports.index');
        Route::get('/entegrasyonlar', [IntegrationController::class, 'index'])->middleware('permission:performance.view')->name('integrations.index');

        // Üyelikler & paketler (faz 39b, §6): subscription.view (global) görür; subscription.manage (finans) yazar.
        Route::prefix('uyelikler')->name('subscriptions.')->group(function () {
            Route::get('/', [SubscriptionController::class, 'index'])->middleware('permission:subscription.view')->name('index');
            Route::get('/yeni', [SubscriptionController::class, 'create'])->middleware('permission:subscription.manage')->name('create');
            Route::post('/', [SubscriptionController::class, 'store'])->middleware('permission:subscription.manage')->name('store');
            Route::get('/{subscription}', [SubscriptionController::class, 'show'])->where('subscription', '[0-9]+')->middleware('permission:subscription.view')->name('show');
            Route::post('/{subscription}/iptal', [SubscriptionController::class, 'cancel'])->where('subscription', '[0-9]+')->middleware('permission:subscription.manage')->name('cancel');
            Route::post('/{subscription}/yenile', [SubscriptionController::class, 'renew'])->where('subscription', '[0-9]+')->middleware('permission:subscription.manage')->name('renew');
        });
        // Etkinlikler (faz 39d, §9): event.view görür; event.manage yazar. {event} slug ile bağlanır.
        Route::prefix('etkinlikler')->name('events.')->group(function () {
            Route::get('/', [EventController::class, 'index'])->middleware('permission:event.view|event.manage')->name('index');
            Route::get('/yeni', [EventController::class, 'create'])->middleware('permission:event.manage')->name('create');
            Route::post('/', [EventController::class, 'store'])->middleware('permission:event.manage')->name('store');
            Route::get('/{event}', [EventController::class, 'show'])->middleware('permission:event.view|event.manage')->name('show');
            Route::get('/{event}/duzenle', [EventController::class, 'edit'])->middleware('permission:event.manage')->name('edit');
            Route::put('/{event}', [EventController::class, 'update'])->middleware('permission:event.manage')->name('update');
            Route::delete('/{event}', [EventController::class, 'destroy'])->middleware('permission:event.manage')->name('destroy');
            Route::post('/{event}/kayit/{registration}', [EventController::class, 'registration'])->middleware('permission:event.manage')->scopeBindings()->name('registration');
        });

        // Franchise (faz 39e, §14): franchise.view listeler; franchise.manage değerlendirir.
        Route::prefix('franchise')->name('franchise.')->group(function () {
            Route::get('/', [FranchiseController::class, 'index'])->middleware('permission:franchise.view|franchise.manage')->name('index');
            Route::get('/{application}', [FranchiseController::class, 'show'])->middleware('permission:franchise.view|franchise.manage')->name('show');
            Route::put('/{application}', [FranchiseController::class, 'update'])->middleware('permission:franchise.manage')->name('update');
        });

        // Finans (faz 39c, §7–8): invoice.view görür; invoice.issue açar/yayınlar; invoice.cancel iptal; payment_allocation.manage tahsilat.
        Route::get('/tahsilat', [CollectionController::class, 'index'])->middleware('permission:invoice.view')->name('collections.index');
        // Tahsilat & belge merkezi (faz 47): manuel tahsilat + iptal, makbuz, geciken ödeme belgesi, belge görüntüle/düzenle/PDF/yazdır, şablonlar.
        Route::prefix('tahsilat')->name('collections.')->group(function () {
            Route::post('/tahsilat', [CollectionController::class, 'storePayment'])->middleware('permission:payment_allocation.manage')->name('payments.store');
            Route::post('/tahsilat/{payment}/iptal', [CollectionController::class, 'cancelPayment'])->where('payment', '[0-9]+')->middleware('permission:payment_allocation.manage')->name('payments.cancel');
            Route::post('/tahsilat/{payment}/makbuz', [CollectionController::class, 'receipt'])->where('payment', '[0-9]+')->middleware('permission:payment_allocation.manage')->name('payments.receipt');
            Route::post('/fatura/{invoice}/gecikme-belgesi', [CollectionController::class, 'overdueNotice'])->where('invoice', '[0-9]+')->middleware('permission:payment_allocation.manage')->name('invoices.notice');
            Route::get('/belge/{document}', [CollectionController::class, 'showDocument'])->where('document', '[0-9]+')->middleware('permission:invoice.view')->name('documents.show');
            Route::put('/belge/{document}', [CollectionController::class, 'updateDocument'])->where('document', '[0-9]+')->middleware('permission:payment_allocation.manage')->name('documents.update');
            Route::post('/belge/{document}/iptal', [CollectionController::class, 'cancelDocument'])->where('document', '[0-9]+')->middleware('permission:payment_allocation.manage')->name('documents.cancel');
            Route::get('/belge/{document}/pdf', [CollectionController::class, 'pdf'])->where('document', '[0-9]+')->middleware('permission:invoice.view')->name('documents.pdf');
            Route::get('/belge/{document}/yazdir', [CollectionController::class, 'print'])->where('document', '[0-9]+')->middleware('permission:invoice.view')->name('documents.print');
            Route::get('/sablon/{kind}', [CollectionController::class, 'template'])->where('kind', 'receipt|overdue_notice')->middleware('permission:invoice.view')->name('templates.edit');
            Route::put('/sablon/{kind}', [CollectionController::class, 'updateTemplate'])->where('kind', 'receipt|overdue_notice')->middleware('permission:invoice.issue')->name('templates.update');
        });
        Route::prefix('faturalar')->name('invoices.')->group(function () {
            Route::get('/', [InvoiceController::class, 'index'])->middleware('permission:invoice.view')->name('index');
            Route::get('/yeni', [InvoiceController::class, 'create'])->middleware('permission:invoice.issue')->name('create');
            Route::post('/', [InvoiceController::class, 'store'])->middleware('permission:invoice.issue')->name('store');
            Route::get('/{invoice}', [InvoiceController::class, 'show'])->where('invoice', '[0-9]+')->middleware('permission:invoice.view')->name('show');
            Route::post('/{invoice}/yayinla', [InvoiceController::class, 'issue'])->where('invoice', '[0-9]+')->middleware('permission:invoice.issue')->name('issue');
            // İptal JIT'li (matris requires_jit): kaynak = fatura; grant /jit ile açılır.
            Route::post('/{invoice}/iptal', [InvoiceController::class, 'cancel'])->where('invoice', '[0-9]+')->middleware('permission:invoice.cancel,,'.InvoiceController::RESOURCE.',invoice')->name('cancel');
            Route::post('/{invoice}/jit', [InvoiceController::class, 'requestJit'])->where('invoice', '[0-9]+')->middleware(['permission:invoice.view', 'throttle:jit-request'])->name('jit');
            Route::post('/{invoice}/tahsilat', [InvoiceController::class, 'payment'])->where('invoice', '[0-9]+')->middleware('permission:payment_allocation.manage')->name('payment');
        });
        Route::prefix('paketler')->name('plans.')->group(function () {
            Route::get('/', [PlanController::class, 'index'])->middleware('permission:subscription.view')->name('index');
            Route::get('/yeni', [PlanController::class, 'create'])->middleware('permission:subscription.manage')->name('create');
            Route::post('/', [PlanController::class, 'store'])->middleware('permission:subscription.manage')->name('store');
            Route::get('/{plan}/duzenle', [PlanController::class, 'edit'])->middleware('permission:subscription.manage')->name('edit');
            Route::put('/{plan}', [PlanController::class, 'update'])->middleware('permission:subscription.manage')->name('update');
            Route::delete('/{plan}', [PlanController::class, 'destroy'])->middleware('permission:subscription.manage')->name('destroy');
        });

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
                Route::put('/indirim', [BookingDeskController::class, 'discount'])->middleware('permission:booking.manage,location')->name('discount');
                Route::put('/planla', [BookingDeskController::class, 'reschedule'])->middleware('permission:booking.manage,location')->name('reschedule');
                Route::post('/iptal', [BookingDeskController::class, 'cancel'])
                    ->middleware('permission:booking.admin_override,location,'.BookingDeskController::RESOURCE.',location')->name('location.cancel');
            });
        });

        // Hizmetler modülü (faz 4): service.view listeler, service.manage yazar; personel, tenant context'siz.
        Route::prefix('hizmetler')->name('services.')->group(function () {
            Route::get('/', [ServiceController::class, 'index'])->middleware('permission:service.view|service.manage')->name('index');
            Route::get('/yeni', [ServiceController::class, 'create'])->middleware('permission:service.manage')->name('create');
            Route::post('/', [ServiceController::class, 'store'])->middleware('permission:service.manage')->name('store');
            Route::get('/{service}/duzenle', [ServiceController::class, 'edit'])->middleware('permission:service.manage')->name('edit');
            Route::put('/{service}', [ServiceController::class, 'update'])->middleware('permission:service.manage')->name('update');
            Route::get('/{service}/sil', [ServiceController::class, 'confirmDelete'])->middleware('permission:service.manage')->name('delete');
            Route::delete('/{service}', [ServiceController::class, 'destroy'])->middleware('permission:service.manage')->name('destroy');
        });

        // Ayar merkezi (§31–33): settings.view görür, settings.manage yazar; ?lokasyon= üzerine yazma.
        Route::get('/ayarlar', [SettingsController::class, 'index'])->middleware('permission:settings.view|settings.manage')->name('settings.index');
        Route::put('/ayarlar', [SettingsController::class, 'update'])->middleware('permission:settings.manage')->name('settings.update');
        // API & Entegrasyonlar merkezi + Sistem sağlığı (faz 52): görüntüleme settings.view; test/yeniden kontrol settings.manage.
        Route::get('/ayarlar/api', [SystemController::class, 'api'])->middleware('permission:settings.view|settings.manage')->name('settings.api');
        Route::post('/ayarlar/api/{key}/test', [SystemController::class, 'test'])->where('key', '[a-z_]+')->middleware(['permission:settings.manage', 'throttle:20,1'])->name('settings.api.test');
        Route::get('/ayarlar/saglik', [SystemController::class, 'health'])->middleware('permission:settings.view|settings.manage')->name('settings.health');
        Route::post('/ayarlar/saglik', [SystemController::class, 'recheck'])->middleware('permission:settings.manage')->name('settings.health.recheck');

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

        // Canlı düzenleme (faz 59): vitrindeki modal tek form gönderimi; yetki hedef türüne göre. Dönüş yalnız site içi yol.
        Route::prefix('canli')->name('live.')->group(function () {
            Route::post('/mod', [LiveEditController::class, 'toggle'])->middleware('permission:website.manage|content.edit|service.manage|geo.edit')->name('toggle');
            Route::post('/gorsel/site', [LiveEditController::class, 'website'])->middleware(['permission:website.manage', 'throttle:media-upload'])->name('website');
            Route::post('/gorsel/bolum', [LiveEditController::class, 'section'])->middleware(['permission:content.edit', 'throttle:media-upload'])->name('section');
            Route::post('/gorsel/icerik/{content}', [LiveEditController::class, 'content'])->where('content', '[0-9]+')->middleware(['permission:content.edit', 'throttle:media-upload'])->name('content');
            Route::post('/gorsel/hizmet/{service}', [LiveEditController::class, 'service'])->middleware(['permission:service.manage', 'throttle:media-upload'])->name('service');
            Route::post('/gorsel/lokasyon/{location}', [LiveEditController::class, 'location'])->middleware(['permission:geo.edit', 'throttle:media-upload'])->name('location');
        });

        // Denetim kaydı (audit.view; global, salt okunur).
        Route::get('/denetim', [AuditController::class, 'index'])->middleware('permission:audit.view')->name('audit.index');

        // Kullanıcı yönetimi (faz 29) — personel daveti + global rol; user.manage.
        Route::prefix('kullanicilar')->name('users.')->middleware('permission:user.manage')->group(function () {
            Route::get('/', [UserController::class, 'index'])->name('index');
            Route::get('/yeni', [UserController::class, 'create'])->name('create');
            Route::post('/', [UserController::class, 'store'])->middleware('throttle:invite')->name('store');
            Route::get('/{user}', [UserController::class, 'show'])->name('show');
            // Hesap durumu ve oturumlar (audit): askıya al / etkinleştir / tüm oturumları kapat.
            Route::post('/{user}/askiya-al', [UserController::class, 'suspend'])->name('suspend');
            Route::post('/{user}/etkinlestir', [UserController::class, 'reactivate'])->name('reactivate');
            Route::post('/{user}/oturumlari-kapat', [UserController::class, 'terminateSessions'])->name('sessions.terminate');
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
            // CMS stüdyo (faz 48): oluştur/kaydet ve yayınla (iki izin birden), önizleme sayfası.
            Route::post('/yayinla', [ContentController::class, 'storePublish'])->middleware(['permission:content.create', 'permission:content.publish'])->name('store.publish');
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
            Route::post('/medya/{media}/kirp', [MediaController::class, 'crop'])->middleware('permission:content.edit')->name('media.crop');
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
                // Görsel editör (faz 49): tek gönderimli taslak kaydı, kayıtlı bloklar, editörden yeni sayfa.
                Route::put('/{website}/taslak', [SiteBuilderController::class, 'saveDraft'])->middleware('permission:content.edit')->name('draft');
                Route::post('/{website}/blok-kaydet', [SiteBuilderController::class, 'presetStore'])->middleware('permission:content.edit')->name('preset.store');
                Route::delete('/{website}/blok/{preset}', [SiteBuilderController::class, 'presetDestroy'])->where('preset', '[0-9]+')->middleware('permission:content.edit')->name('preset.destroy');
                Route::post('/{website}/sayfa', [SiteBuilderController::class, 'pageStore'])->middleware('permission:content.create')->name('page.store');
            });

            // Vitrin blokları (faz 10): doğrudan canlıya çıkar -> content.publish.
            // Blok kütüphanesi (faz 50): liste/önizleme content.edit|publish; şablon işlemleri content.edit. Düzenleme görsel editörde.
            Route::get('/bloklar', [SiteBlockController::class, 'index'])->middleware('permission:content.edit|content.publish')->name('blocks');
            Route::post('/bloklar/kayitli', [SiteBlockController::class, 'presetStore'])->middleware('permission:content.edit')->name('blocks.preset.store');
            Route::put('/bloklar/kayitli/{preset}', [SiteBlockController::class, 'presetUpdate'])->where('preset', '[0-9]+')->middleware('permission:content.edit')->name('blocks.preset.update');
            Route::post('/bloklar/kayitli/{preset}/kopyala', [SiteBlockController::class, 'presetDuplicate'])->where('preset', '[0-9]+')->middleware('permission:content.edit')->name('blocks.preset.duplicate');
            Route::delete('/bloklar/kayitli/{preset}', [SiteBlockController::class, 'presetDestroy'])->where('preset', '[0-9]+')->middleware('permission:content.edit')->name('blocks.preset.destroy');
            Route::get('/bloklar/kayitli/{preset}/onizleme', [SiteBlockController::class, 'presetPreview'])->where('preset', '[0-9]+')->middleware('permission:content.edit|content.publish')->name('blocks.preset.preview');
            Route::put('/bloklar/metinler', [SiteBlockController::class, 'updateTexts'])->middleware('permission:content.publish')->name('blocks.texts');
            Route::put('/bloklar/{block}', [SiteBlockController::class, 'update'])->where('block', '[a-z_]+')->middleware('permission:content.publish')->name('blocks.update');
            Route::get('/{content}', [ContentController::class, 'show'])->middleware($canSee)->name('show');
            Route::get('/{content}/duzenle', [ContentController::class, 'edit'])->middleware('permission:content.edit')->name('edit');
            Route::put('/{content}', [ContentController::class, 'update'])->middleware('permission:content.edit')->name('update');
            Route::put('/{content}/kaydet-ve-yayinla', [ContentController::class, 'savePublish'])->middleware(['permission:content.edit', 'permission:content.publish'])->name('update.publish');
            Route::get('/{content}/onizleme', [ContentController::class, 'preview'])->middleware($canSee)->name('preview');
            Route::get('/{content}/sil', [ContentController::class, 'confirmDelete'])->middleware('permission:content.archive')->name('delete');
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

            // SEO & GEO Command Center (faz 60): sağlık merkezi + Schema Manager. Otomatik düzeltme iki kapı:
            // edit → seo.edit; critical (canonical/sitemap ayarı) → seo.settings + JIT. Her düzeltme onay kutusu ister.
            Route::get('/merkez', [SeoCenterController::class, 'home'])->middleware('permission:seo.view')->name('center.home');
            Route::get('/{website}/merkez', [SeoCenterController::class, 'center'])->middleware('permission:seo.view')->name('center');
            Route::post('/{website}/merkez/karar', [SeoCenterController::class, 'decide'])->middleware('permission:seo.edit')->name('center.decide');
            Route::post('/{website}/merkez/duzelt', [SeoCenterController::class, 'fix'])->middleware('permission:seo.edit')->name('center.fix');
            Route::post('/{website}/merkez/duzelt-kritik', [SeoCenterController::class, 'fixCritical'])->middleware('permission:seo.settings,,seo_settings,website')->name('center.fix-critical');
            Route::get('/sema', [SeoCenterController::class, 'schemaHome'])->middleware('permission:seo.view')->name('schema.home');
            Route::get('/{website}/sema', [SeoCenterController::class, 'schema'])->middleware('permission:seo.view')->name('schema');

            // Akıllı URL / yönlendirme merkezi (faz 54): seo.view görür, seo.edit yazar, seo.audit botu çalıştırır.
            Route::get('/yonlendirmeler', [RedirectController::class, 'home'])->middleware('permission:seo.view')->name('redirects.home');
            Route::get('/{website}/yonlendirmeler/{sekme?}', [RedirectController::class, 'index'])->middleware('permission:seo.view')->name('redirects.index');
            Route::post('/{website}/yonlendirmeler', [RedirectController::class, 'store'])->middleware('permission:seo.edit')->name('redirects.store');
            Route::put('/{website}/yonlendirmeler/{redirect}', [RedirectController::class, 'update'])->where('redirect', '[0-9]+')->middleware('permission:seo.edit')->name('redirects.update');
            Route::delete('/{website}/yonlendirmeler/{redirect}', [RedirectController::class, 'destroy'])->where('redirect', '[0-9]+')->middleware('permission:seo.edit')->name('redirects.destroy');
            Route::post('/{website}/yonlendirmeler/{redirect}/onayla', [RedirectController::class, 'approve'])->where('redirect', '[0-9]+')->middleware('permission:seo.edit')->name('redirects.approve');
            Route::post('/{website}/yonlendirmeler/{redirect}/duzlestir', [RedirectController::class, 'flatten'])->where('redirect', '[0-9]+')->middleware('permission:seo.edit')->name('redirects.flatten');
            Route::post('/{website}/yonlendirmeler/404/{log}/durum', [RedirectController::class, 'notFoundStatus'])->where('log', '[0-9]+')->middleware('permission:seo.edit')->name('redirects.404.status');
            Route::post('/{website}/yonlendirmeler/tara', [RedirectController::class, 'scan'])->middleware(['permission:seo.audit', 'throttle:10,1'])->name('redirects.scan');
            Route::put('/{website}/ayarlar', [SeoController::class, 'settings'])
                ->middleware('permission:seo.settings,,seo_settings,website')->name('settings');
            // {izin}: settings (seo.settings) · integrations (seo.integrations) · entity (geo.settings, geo_entity).
            Route::post('/{website}/jit/{izin?}', [SeoController::class, 'requestJit'])->where('izin', 'settings|integrations|entity')
                ->middleware(['permission:seo.view', 'throttle:jit-request'])->name('jit');

            // Gelişmiş ayarlar (faz 44): sekmeli; yazma rotası sekme moduna göre ayrılır (controller modu doğrular).
            Route::get('/gelismis', [SeoSettingsController::class, 'home'])->middleware('permission:seo.view')->name('settings.home');
            Route::get('/{website}/gelismis/{sekme?}', [SeoSettingsController::class, 'show'])->middleware('permission:seo.view')->name('settings.show');
            Route::put('/{website}/gelismis/duzenle/{sekme}', [SeoSettingsController::class, 'updateEdit'])->middleware('permission:seo.edit')->name('settings.edit');
            Route::put('/{website}/gelismis/kritik/{sekme}', [SeoSettingsController::class, 'updateCritical'])->middleware('permission:seo.settings,,seo_settings,website')->name('settings.critical');
            Route::put('/{website}/gelismis/entegrasyon/{sekme}', [SeoSettingsController::class, 'updateIntegration'])->middleware('permission:seo.integrations,,seo_settings,website')->name('settings.integration');
            Route::put('/{website}/gelismis/varlik/{sekme}', [SeoSettingsController::class, 'updateEntity'])->middleware('permission:geo.settings,,geo_entity,website')->name('settings.entity');
        });

        // --- GEO / Entity (faz 16-17) — personel, tenant context'siz -----------
        Route::prefix('geo')->name('geo.')->group(function () {
            Route::get('/', [GeoController::class, 'index'])->middleware('permission:geo.view')->name('index');
            Route::get('/lokasyon/{location}', [GeoController::class, 'edit'])->middleware('permission:geo.edit')->name('edit');
            Route::get('/lokasyon-yeni', [GeoController::class, 'create'])->middleware('permission:geo.edit')->name('create');
            Route::post('/lokasyon', [GeoController::class, 'store'])->middleware('permission:geo.edit')->name('store');
            Route::put('/lokasyon/{location}/kunye', [GeoController::class, 'updateBasics'])->middleware('permission:geo.edit')->name('basics');
            Route::get('/lokasyon/{location}/sil', [GeoController::class, 'confirmDelete'])->middleware('permission:geo.publish')->name('delete');
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
            // Masa & ofis envanteri (audit P0-2): lokasyon künyesinin parçası, geo.edit.
            Route::get('/lokasyon/{location}/alanlar', [LocationSpaceController::class, 'index'])->middleware('permission:geo.edit')->name('spaces.index');
            Route::post('/lokasyon/{location}/alanlar', [LocationSpaceController::class, 'store'])->middleware('permission:geo.edit')->name('spaces.store');
            Route::put('/lokasyon/{location}/alanlar/{space}', [LocationSpaceController::class, 'update'])->where('space', '[0-9]+')->middleware('permission:geo.edit')->name('spaces.update');
            Route::delete('/lokasyon/{location}/alanlar/{space}', [LocationSpaceController::class, 'destroy'])->where('space', '[0-9]+')->middleware('permission:geo.edit')->name('spaces.destroy');
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
            // 360° üye merkezi (faz 51; dizin faz 39): görünürlük şirket listesiyle aynı (CompanyService::visibleTo).
            // Yazma yetkileri şirket kapsamlı: profil membership.manage, sözleşme subscription.manage, ek harcama invoice.issue.
            Route::get('/uyeler', [MemberCenterController::class, 'index'])->name('members.index');
            Route::get('/uyeler/yeni', [MemberCenterController::class, 'create'])->name('members.create');
            Route::post('/uyeler/{company}', [MemberCenterController::class, 'store'])->middleware('permission:membership.manage,company')->name('members.store');
            Route::get('/uyeler/{member}', [MemberCenterController::class, 'show'])->where('member', '[0-9]+')->name('members.show');
            Route::get('/uyeler/{member}/duzenle', [MemberCenterController::class, 'edit'])->where('member', '[0-9]+')->name('members.edit');
            Route::get('/uyeler/{member}/sozlesme/{contract}/dosya', [MemberCenterController::class, 'contractFile'])->where(['member' => '[0-9]+', 'contract' => '[0-9]+'])->name('members.contract.file');
            Route::prefix('/uyeler/{company}/{member}')->where(['member' => '[0-9]+', 'contract' => '[0-9]+'])->name('members.')->group(function () {
                Route::put('/', [MemberCenterController::class, 'update'])->middleware('permission:membership.manage,company')->name('update');
                Route::post('/harcama', [MemberCenterController::class, 'chargeStore'])->middleware('permission:invoice.issue,company')->name('charge.store');
                Route::post('/sozlesme', [MemberCenterController::class, 'contractStore'])->middleware('permission:subscription.manage,company')->name('contract.store');
                Route::put('/sozlesme/{contract}', [MemberCenterController::class, 'contractUpdate'])->middleware('permission:subscription.manage,company')->name('contract.update');
                Route::post('/sozlesme/{contract}/sonlandir', [MemberCenterController::class, 'contractEnd'])->middleware('permission:subscription.manage,company')->name('contract.end');
                Route::post('/sozlesme/{contract}/belge', [MemberCenterController::class, 'contractDocument'])->middleware('permission:subscription.manage,company')->name('contract.document');
            });
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
            // Müşteri üyelik görünümü (faz 39b): subscription.view, şirket kapsamı.
            Route::get('/sirketler/{company}/uyelik', [CompanySubscriptionController::class, 'index'])
                ->middleware('permission:subscription.view,company')
                ->name('companies.subscriptions.index');
            // Müşteri alanları (audit P0-2): space.view, şirket kapsamı; salt okunur.
            Route::get('/sirketler/{company}/alanlar', [CompanySpaceController::class, 'index'])
                ->middleware('permission:space.view,company')
                ->name('companies.spaces.index');
            // Müşteri faturaları (faz 39c): invoice.view, şirket kapsamı; taslak görünmez.
            Route::get('/sirketler/{company}/faturalar', [CompanyInvoiceController::class, 'index'])
                ->middleware('permission:invoice.view,company')
                ->name('companies.invoices.index');
            Route::get('/sirketler/{company}/faturalar/{invoice}', [CompanyInvoiceController::class, 'show'])->where('invoice', '[0-9]+')
                ->middleware('permission:invoice.view,company')
                ->name('companies.invoices.show');
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

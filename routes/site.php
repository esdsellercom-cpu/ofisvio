<?php

/**
 * Vitrin route'ları.
 *
 * Bu dosya routes/web.php'den yüklenir:
 *   require __DIR__.'/site.php';
 *
 * Buradaki route'lar KİMLİK DOĞRULAMASI İSTEMEZ ve tenant middleware'i
 * taşımaz — vitrin herkese açıktır. Panel route'ları (auth + tenant +
 * permission zinciri) ayrı grupta kalır; bkz. routes/panel.php.
 */

use App\Http\Controllers\Site\HomeController;
use App\Http\Controllers\Site\LeadController;
use Illuminate\Support\Facades\Route;

Route::get('/', HomeController::class)->name('site.home');

// Form gönderimleri: throttle ile korunur. Bot tuzağı (website alanı)
// StoreLeadRequest içinde; captcha eklenene kadar ilk savunma bu ikisi.
Route::post('/talep', [LeadController::class, 'store'])
    ->middleware('throttle:10,1')
    ->name('site.leads.store');

/*
 * Giriş Fortify'dadır (/login, /logout, /forgot-password, /reset-password).
 * /giris yalnızca eski bağlantılar ve Türkçe URL alışkanlığı için kalıcı
 * yönlendirmedir; header route('login')'i kullanır.
 */
Route::redirect('/giris', '/login', 301);

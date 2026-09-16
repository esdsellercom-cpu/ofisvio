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

use App\Http\Controllers\Site\ContentController;
use App\Http\Controllers\Site\HomeController;
use App\Http\Controllers\Site\LeadController;
use App\Http\Controllers\Site\LocationController;
use App\Http\Controllers\Site\SeoController;
use Illuminate\Support\Facades\Route;

// public.cache: misafire public+ETag, oturum açmışa private/no-store (faz 12).
Route::get('/', HomeController::class)->middleware('public.cache')->name('site.home');

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

/*
 * CMS (faz 9): yayındaki yazı ve sayfalar. /{slug} EN SONDA kalır — önce
 * tanımlı tüm route'lar eşleşir; slug regex'i /panel, /login gibi yolları
 * zaten dışlar (küçük harf-rakam-tire).
 */
// SEO Engine (faz 15/21): geçerli website'e göre robots.txt ve sitemap.xml.
Route::get('/robots.txt', [SeoController::class, 'robots'])->middleware('public.cache')->name('site.robots');
Route::get('/sitemap.xml', [SeoController::class, 'sitemap'])->middleware('public.cache')->name('site.sitemap');

// GEO (faz 16): lokasyon sayfaları — yalnızca Ofisvio vitrini (müşteri sitesinde 404).
Route::get('/lokasyonlar', [LocationController::class, 'index'])->middleware('public.cache')->name('site.locations');
Route::get('/lokasyon/{slug}', [LocationController::class, 'show'])->where('slug', '[a-z0-9-]+')->middleware('public.cache')->name('site.location');

Route::get('/blog', [ContentController::class, 'posts'])->middleware('public.cache')->name('site.posts');
Route::get('/blog/{slug}', [ContentController::class, 'post'])->where('slug', '[a-z0-9-]+')->middleware('public.cache')->name('site.post');
Route::get('/{slug}', [ContentController::class, 'page'])->where('slug', '(?!panel$|login$|logout$|blog$|lokasyonlar$|up$)[a-z0-9-]+')->middleware('public.cache')->name('site.page');

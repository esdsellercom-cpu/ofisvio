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

use App\Http\Controllers\Site\BookingController;
use App\Http\Controllers\Site\ContentController;
use App\Http\Controllers\Site\EventController;
use App\Http\Controllers\Site\FranchiseController;
use App\Http\Controllers\Site\HomeController;
use App\Http\Controllers\Site\LeadController;
use App\Http\Controllers\Site\LocationController;
use App\Http\Controllers\Site\SeoController;
use App\Http\Controllers\Site\ServiceController;
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

// Rezervasyon (booking engine v2, §57): uygunluk canlı — önbellek YOK; talep throttle'lı;
// müşteri talebini tahmin edilemez uuid ile görür.
// Sayfa kurucu önizlemesi: imzalı + süreli URL (panelden üretilir); önbellek yok, noindex (SiteLayoutComposer).
Route::get('/onizleme/{website}', [HomeController::class, 'preview'])->where('website', '[0-9]+')->middleware('signed')->name('site.preview');

Route::get('/rezervasyon', [BookingController::class, 'index'])->name('site.booking.index');
Route::post('/rezervasyon', [BookingController::class, 'store'])->middleware('throttle:booking-public')->name('site.booking.store');
Route::get('/rezervasyon/{uuid}', [BookingController::class, 'show'])->where('uuid', '[0-9a-f-]{36}')->name('site.booking.show');

/*
 * CMS (faz 9): yayındaki yazı ve sayfalar. /{slug} EN SONDA kalır — önce
 * tanımlı tüm route'lar eşleşir; slug regex'i /panel, /login gibi yolları
 * zaten dışlar (küçük harf-rakam-tire).
 */
// SEO Engine (faz 15/21): geçerli website'e göre robots.txt ve sitemap.xml.
Route::get('/robots.txt', [SeoController::class, 'robots'])->middleware('public.cache')->name('site.robots');
Route::get('/sitemap.xml', [SeoController::class, 'sitemap'])->middleware('public.cache')->name('site.sitemap');

// GEO (faz 16): lokasyon sayfaları — yalnızca Ofisvio vitrini (müşteri sitesinde 404).
// Hizmetler (faz 4): liste + detay (hizmet ↔ lokasyon ilişkisi).
Route::get('/cozumler', [ServiceController::class, 'index'])->middleware('public.cache')->name('site.services');
Route::get('/cozum/{slug}', [ServiceController::class, 'show'])->where('slug', '[a-z0-9-]+')->middleware('public.cache')->name('site.service');

// Etkinlikler (faz 39d) — yalnız varsayılan sitede; kayıt throttle'lı. Franchise başvurusu (faz 39e).
Route::get('/etkinlikler', [EventController::class, 'index'])->middleware('public.cache')->name('site.events');
Route::get('/etkinlik/{slug}', [EventController::class, 'show'])->where('slug', '[a-z0-9-]+')->name('site.event');
Route::post('/etkinlik/{slug}/kayit', [EventController::class, 'register'])->where('slug', '[a-z0-9-]+')->middleware('throttle:10,1')->name('site.event.register');
Route::get('/franchise', [FranchiseController::class, 'show'])->middleware('public.cache')->name('site.franchise');
Route::post('/franchise', [FranchiseController::class, 'store'])->middleware('throttle:5,1')->name('site.franchise.store');

Route::get('/lokasyonlar', [LocationController::class, 'index'])->middleware('public.cache')->name('site.locations');
Route::get('/lokasyon/{slug}', [LocationController::class, 'show'])->where('slug', '[a-z0-9-]+')->middleware('public.cache')->name('site.location');

Route::get('/blog', [ContentController::class, 'posts'])->middleware('public.cache')->name('site.posts');
Route::get('/blog/etiket/{tag}', [ContentController::class, 'tag'])->where('tag', '[a-z0-9-]+')->middleware('public.cache')->name('site.tag');
Route::get('/blog/kategori/{category}', [ContentController::class, 'category'])->where('category', '[a-z0-9-]+')->middleware('public.cache')->name('site.category');
Route::get('/blog/{slug}', [ContentController::class, 'post'])->where('slug', '[a-z0-9-]+')->middleware('public.cache')->name('site.post');
Route::get('/{parent}/{slug}', [ContentController::class, 'childPage'])->where(['parent' => '(?!panel$|login$|logout$|blog$|lokasyon$|lokasyonlar$|cozum$|cozumler$|rezervasyon$|etkinlik$|etkinlikler$|franchise$|up$)[a-z0-9-]+', 'slug' => '[a-z0-9-]+'])->middleware('public.cache')->name('site.page.child');
Route::get('/{slug}', [ContentController::class, 'page'])->where('slug', '(?!panel$|login$|logout$|blog$|lokasyonlar$|cozumler$|rezervasyon$|etkinlikler$|franchise$|up$)[a-z0-9-]+')->middleware('public.cache')->name('site.page');

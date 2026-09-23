<?php

use App\Http\Controllers\Install\InstallController;
use App\Http\Middleware\EnsureInstallable;
use App\Http\Middleware\ThrottleInstall;
use Illuminate\Support\Facades\Route;

/*
 * Web kurulum sihirbazı (faz 62). Kurulu sistemde bu rotalar 404'tür (EnsureInstallable).
 *
 * Sıra: web.php bu dosyayı panel/site rotalarından ÖNCE yükler — yoksa /install vitrin sayfası slug'ı sanılır.
 * Ortam düzeltmesi (oturum/önbellek sürücüleri, APP_DEBUG, APP_KEY) global InstallEnvironment middleware'inde,
 * oturum başlamadan önce yapılır. Hız sınırı adlandırılmış limiter yerine ThrottleInstall'dadır (dosya önbelleği;
 * taze kurulumda `cache` tablosu yoktur).
 */
Route::middleware(['web', ThrottleInstall::class, EnsureInstallable::class])->prefix('install')->name('install.')->group(function (): void {
    Route::get('/', [InstallController::class, 'token'])->name('token');
    Route::post('/', [InstallController::class, 'verify'])->middleware(ThrottleInstall::class.':token')->name('verify');

    Route::get('/gereksinimler', [InstallController::class, 'requirements'])->name('requirements');
    Route::get('/veritabani', [InstallController::class, 'database'])->name('database');
    Route::post('/veritabani', [InstallController::class, 'storeDatabase'])->name('database.store');
    Route::get('/site', [InstallController::class, 'site'])->name('site');
    Route::post('/site', [InstallController::class, 'storeSite'])->name('site.store');
    Route::get('/kurulum', [InstallController::class, 'setup'])->name('setup');
    Route::post('/kurulum/tablolar', [InstallController::class, 'runMigrate'])->name('setup.migrate');
    Route::post('/kurulum/veri', [InstallController::class, 'runSeed'])->name('setup.seed');
    Route::get('/yonetici', [InstallController::class, 'admin'])->name('admin');
    Route::post('/yonetici', [InstallController::class, 'storeAdmin'])->name('admin.store');
    Route::get('/bitir', [InstallController::class, 'finish'])->name('finish');
    Route::post('/bitir', [InstallController::class, 'complete'])->name('complete');
});

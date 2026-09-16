<?php

use App\Http\Controllers\WebhookController;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;

// Sıra ÖNEMLİ: site.php sonunda /{slug} yakalayıcısı var; panel route'ları
// ondan önce tanımlanmalı ki /panel bir "sayfa slug'ı" sanılmasın.
require __DIR__.'/panel.php';  // panel — auth -> staff.2fa -> tenant -> permission
require __DIR__.'/site.php';   // vitrin — kimlik doğrulaması yok

// Gelen webhook'lar (faz 5): sunucudan sunucuya, CSRF yok, imza WebhookReceiver'da.
Route::post('/webhooks/{provider}', WebhookController::class)
    ->where('provider', '[a-z_]+')
    ->withoutMiddleware([ValidateCsrfToken::class])
    ->middleware('throttle:webhook')
    ->name('webhooks.receive');

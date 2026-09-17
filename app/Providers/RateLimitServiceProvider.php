<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

/**
 * Panel hız sınırları — amaca özel, AYRI anahtarlı.
 *
 * NEDEN ADLANDIRILMIŞ LIMITER: `throttle:20,1` biçimindeki sayısal sınır
 * anahtarı yalnızca kullanıcı kimliğinden üretir; farklı route'lar aynı
 * sayacı paylaşır. Sonuç: 20 belge yükleyen kullanıcı ardından üye davet
 * edemezdi (429). CI'da Redis önbelleğiyle bu paylaşım testler arasında da
 * sızdı ve ilk pipeline koşusunda 7 testi kırdı. Her sınır kendi anahtarını
 * ('<ad>|<kullanıcı id>') taşır; oturumsuz isteklerde IP kullanılır.
 *
 * Login limiter'ı FortifyServiceProvider'dadır (e-posta + IP).
 */
class RateLimitServiceProvider extends ServiceProvider
{
    /** @var array<string, int> limiter adı => dakikada izin verilen istek */
    public const LIMITS = [
        'kyc-upload' => 20,
        'jit-request' => 10,
        'invite' => 20,
        'booking' => 20, // rezervasyon oluşturma (panel)
        'booking-public' => 5, // vitrin talebi (IP)
        'media-upload' => 30, // görsel yükleme (karantina zinciri maliyetli)
        'context-switch' => 30,
        'webhook' => 120, // sağlayıcı yeniden teslimleri; IP bazlı (oturum yok)
    ];

    public function boot(): void
    {
        foreach (self::LIMITS as $name => $perMinute) {
            RateLimiter::for($name, fn (Request $request) => Limit::perMinute($perMinute)
                ->by($name.'|'.($request->user()?->getAuthIdentifier() ?? $request->ip())));
        }
    }
}

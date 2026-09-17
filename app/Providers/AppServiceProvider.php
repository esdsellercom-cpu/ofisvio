<?php

namespace App\Providers;

use Illuminate\Pagination\Paginator;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Sayfalama: tasarım sistemi (public/css/ofisvio.css) Tailwind taşımaz; kendi görünümümüz.
        Paginator::defaultView('vendor.pagination.ofisvio');
        Paginator::defaultSimpleView('vendor.pagination.ofisvio');

        // Şifre politikası (audit S-3): en az 12 karakter, harf + rakam; üretimde sızmış şifre listesi
        // (HIBP k-anonimlik sorgusu — dış ağ istediği için yalnız üretimde). Fortify eylemleri
        // Password::default() üzerinden bunu kullanır.
        Password::defaults(function () {
            $rule = Password::min(12)->letters()->numbers();

            return $this->app->isProduction() ? $rule->uncompromised() : $rule;
        });
    }
}

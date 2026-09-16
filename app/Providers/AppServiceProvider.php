<?php

namespace App\Providers;

use Illuminate\Pagination\Paginator;
use Illuminate\Support\ServiceProvider;

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
    }
}

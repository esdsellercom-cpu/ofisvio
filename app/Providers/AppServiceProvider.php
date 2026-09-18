<?php

namespace App\Providers;

use App\Integrations\IntegrationConfigRepository;
use App\Integrations\IntegrationHub;
use App\Models\Content;
use App\Models\Location;
use App\Models\SeoLandingPage;
use App\Models\Service;
use App\Models\SiteRevision;
use App\Models\Website;
use App\Observers\CacheCascadeObserver;
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
        // Entegrasyon üst yazımları istek başına tek okuma (faz 61b).
        $this->app->singleton(IntegrationConfigRepository::class);
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

        // Entegrasyon merkezi (faz 61b): panelden girilen SMTP / S3 alanları çalışma zamanı config'ine (env boşsa ya da üstüne).
        $this->app->make(IntegrationHub::class)->applyRuntime();

        // Önbellek geçersizleme kaskadı (faz 60f): CMS değişikliği → site sürümü atlar + cache_events izi.
        foreach ([Service::class, Location::class, Content::class, SeoLandingPage::class, Website::class, SiteRevision::class] as $model) {
            $model::observe(CacheCascadeObserver::class);
        }

        // Şifre politikası (audit S-3): en az 12 karakter, harf + rakam; üretimde sızmış şifre listesi
        // (HIBP k-anonimlik sorgusu — dış ağ istediği için yalnız üretimde). Fortify eylemleri
        // Password::default() üzerinden bunu kullanır.
        Password::defaults(function () {
            $rule = Password::min(12)->letters()->numbers();

            return $this->app->isProduction() ? $rule->uncompromised() : $rule;
        });
    }
}

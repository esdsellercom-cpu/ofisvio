<?php

namespace App\Providers;

use App\Services\AuthorizationService;
use App\Services\CompanyActivationService;
use App\Services\CompanyService;
use App\Services\ContextSwitchService;
use App\Services\JitAccessService;
use App\Services\KycQueueService;
use App\Services\KycService;
use App\Services\LeadService;
use App\Services\OrganizationOnboardingService;
use App\Services\TenantContext;
use App\View\Composers\PanelLayoutComposer;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

/**
 * Servis kayıtları.
 *
 * BU DOSYA BİR HATA DÜZELTMESİDİR — atlanırsa tenant savunması sessizce bozulur.
 *
 * TenantContext DURUM TAŞIR (systemMode bayrağı). Laravel'in container'ı
 * binding tanımlı değilken app(TenantContext::class) her çağrıda YENİ bir
 * örnek üretir. Sonuç: runAsSystem() bayrağı A örneğinde kaldırır, TenantScope
 * ise B örneğine sorar ve daima false görür — yani runAsSystem() HİÇ ÇALIŞMAZ.
 *
 * Bu, SQLite harness'ında görünmeyen türden bir hatadır: harness Laravel'in
 * container'ını hiç kullanmaz, bu yüzden mantık doğru olduğu halde çalışma
 * zamanında bozuktu.
 */
class TenantServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // İstek boyunca TEK örnek — systemMode bayrağının anlamlı olması için şart.
        $this->app->singleton(TenantContext::class, fn ($app) => new TenantContext($app->make(Session::class)));

        // Durumsuz servisler; singleton olmaları zorunlu değil ama gereksiz
        // yeniden kurulumu önler ve bağımlılık grafiğini açık hale getirir.
        $this->app->singleton(AuthorizationService::class);
        $this->app->singleton(JitAccessService::class);
        $this->app->singleton(CompanyActivationService::class);
        $this->app->singleton(ContextSwitchService::class);
        $this->app->singleton(KycService::class);
        $this->app->singleton(LeadService::class);
        $this->app->singleton(CompanyService::class);
        $this->app->singleton(OrganizationOnboardingService::class);
        $this->app->singleton(KycQueueService::class);
    }

    public function boot(): void
    {
        // Panel layout'u VE panel sayfaları aktif organizasyon/personel bilgisini
        // tek noktadan alır. Sayfalar da listede: @extends eden görünüm layout'tan
        // ÖNCE derlenir, yalnızca layout'a bağlı composer sayfaya değişken vermez.
        View::composer(['layouts.panel', 'panel.*'], PanelLayoutComposer::class);
    }
}

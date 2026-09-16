<?php

use App\Providers\AppServiceProvider;
use App\Providers\AuthorizationServiceProvider;
use App\Providers\FortifyServiceProvider;
use App\Providers\RateLimitServiceProvider;
use App\Providers\SecurityServiceProvider;
use App\Providers\TenantServiceProvider;

return [
    AppServiceProvider::class,
    AuthorizationServiceProvider::class,
    FortifyServiceProvider::class,
    RateLimitServiceProvider::class,
    SecurityServiceProvider::class,
    TenantServiceProvider::class,
];

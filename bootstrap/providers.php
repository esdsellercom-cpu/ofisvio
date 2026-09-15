<?php

use App\Providers\AppServiceProvider;
use App\Providers\AuthorizationServiceProvider;
use App\Providers\TenantServiceProvider;

return [
    AppServiceProvider::class,
    TenantServiceProvider::class,
    AuthorizationServiceProvider::class,
];

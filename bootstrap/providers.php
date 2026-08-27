<?php

use App\Providers\AppServiceProvider;
use App\Providers\AuthorizationServiceProvider;
use App\Providers\FortifyServiceProvider;
use App\Providers\ProvisioningServiceProvider;

return [
    AppServiceProvider::class,
    FortifyServiceProvider::class,
    AuthorizationServiceProvider::class,
    ProvisioningServiceProvider::class,
];

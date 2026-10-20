<?php

use App\Modules\Central\CentralServiceProvider;
use App\Modules\Core\CoreServiceProvider;
use App\Modules\Identity\IdentityServiceProvider;
use App\Providers\HorizonServiceProvider;
use App\Providers\TenancyServiceProvider;

return [
    HorizonServiceProvider::class,
    TenancyServiceProvider::class,

    // Modules (Core first: others register into its catalogs and registries).
    CoreServiceProvider::class,
    CentralServiceProvider::class,
    IdentityServiceProvider::class,
];

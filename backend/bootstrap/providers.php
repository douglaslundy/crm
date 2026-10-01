<?php

use App\Modules\Catalog\Providers\CatalogServiceProvider;
use App\Modules\Customers\Providers\CustomersServiceProvider;
use App\Modules\Fiscal\Providers\FiscalServiceProvider;
use App\Modules\Identity\Providers\IdentityServiceProvider;
use App\Modules\Platform\Providers\PlatformServiceProvider;
use App\Modules\Tenancy\Providers\TenancyServiceProvider;
use App\Providers\AppServiceProvider;

return [
    AppServiceProvider::class,
    TenancyServiceProvider::class,
    PlatformServiceProvider::class,
    IdentityServiceProvider::class,
    CatalogServiceProvider::class,
    CustomersServiceProvider::class,
    FiscalServiceProvider::class,
];

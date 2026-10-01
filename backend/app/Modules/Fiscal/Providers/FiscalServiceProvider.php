<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Providers;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

final class FiscalServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Route::middleware('api')->prefix('api/app')->group(__DIR__.'/../Http/routes-app.php');
    }
}

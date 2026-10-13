<?php

declare(strict_types=1);

namespace App\Modules\Crm\Providers;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

final class CrmServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $rotas = __DIR__.'/../Http/routes-app.php';

        if (is_file($rotas)) {
            Route::middleware('api')->prefix('api/app/crm')->group($rotas);
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Billing\Providers;

use App\Modules\Billing\Console\AplicarInadimplenciaCommand;
use App\Modules\Billing\Console\GerarBoletosDoCicloCommand;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

final class BillingServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([GerarBoletosDoCicloCommand::class, AplicarInadimplenciaCommand::class]);
        }

        Route::middleware(['api', 'throttle:120,1'])->prefix('api/webhooks')->group(__DIR__.'/../Http/routes-webhooks.php');
    }
}

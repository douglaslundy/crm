<?php

declare(strict_types=1);

namespace App\Modules\Billing\Providers;

use App\Modules\Billing\Console\AplicarInadimplenciaCommand;
use App\Modules\Billing\Console\GerarBoletosDoCicloCommand;
use App\Modules\Billing\Domain\Contracts\GatewayDeCobranca;
use App\Modules\Billing\Infrastructure\MercadoPagoGateway;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

final class BillingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(GatewayDeCobranca::class, fn (): GatewayDeCobranca => new MercadoPagoGateway(
            config('billing.mercadopago.access_token'),
            (string) config('billing.mercadopago.base_url'),
            rtrim((string) config('app.url'), '/').'/api/webhooks/mercadopago',
            rtrim((string) config('app.frontend_url', config('app.url')), '/').'/assinatura',
        ));
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([GerarBoletosDoCicloCommand::class, AplicarInadimplenciaCommand::class]);
        }

        Route::middleware(['api', 'throttle:120,1'])->prefix('api/webhooks')->group(__DIR__.'/../Http/routes-webhooks.php');
    }
}

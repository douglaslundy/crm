<?php

declare(strict_types=1);

namespace App\Modules\Customers\Providers;

use App\Modules\Customers\Application\ContadorDeClientes;
use App\Modules\Platform\Application\RegistroDeContadores;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

final class CustomersServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->app->make(RegistroDeContadores::class)->registrar(new ContadorDeClientes);

        Route::middleware('api')->prefix('api/app')->group(__DIR__.'/../Http/routes-app.php');
        Route::middleware('api')->prefix('api/v1')->group(__DIR__.'/../Http/routes-v1.php');
    }
}

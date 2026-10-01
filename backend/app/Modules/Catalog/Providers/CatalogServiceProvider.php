<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Providers;

use App\Modules\Catalog\Application\ContadorDeProdutos;
use App\Modules\Catalog\Application\ContadorDeServicos;
use App\Modules\Platform\Application\RegistroDeContadores;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

final class CatalogServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->app->make(RegistroDeContadores::class)->registrar(new ContadorDeProdutos);
        $this->app->make(RegistroDeContadores::class)->registrar(new ContadorDeServicos);

        Route::middleware('api')->prefix('api/app')->group(__DIR__.'/../Http/routes-app.php');
    }
}

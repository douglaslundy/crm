<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Providers;

use App\Modules\Catalog\Application\CatalogoDeItensDoCatalog;
use App\Modules\Catalog\Application\ContadorDeProdutos;
use App\Modules\Catalog\Application\ContadorDeServicos;
use App\Modules\Crm\Domain\Contracts\CatalogoDeItens;
use App\Modules\Platform\Application\RegistroDeContadores;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

final class CatalogServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(CatalogoDeItens::class, CatalogoDeItensDoCatalog::class);
    }

    public function boot(): void
    {
        $this->app->make(RegistroDeContadores::class)->registrar(new ContadorDeProdutos);
        $this->app->make(RegistroDeContadores::class)->registrar(new ContadorDeServicos);

        Route::middleware('api')->prefix('api/app')->group(__DIR__.'/../Http/routes-app.php');
        Route::middleware('api')->prefix('api/v1')->group(__DIR__.'/../Http/routes-v1.php');
    }
}

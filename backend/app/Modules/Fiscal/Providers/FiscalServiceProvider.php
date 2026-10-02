<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Providers;

use App\Modules\Fiscal\Application\ContadorDeDocumentosMes;
use App\Modules\Platform\Application\RegistroDeContadores;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

final class FiscalServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->app->make(RegistroDeContadores::class)->registrar(new ContadorDeDocumentosMes);

        Route::middleware('api')->prefix('api/app')->group(__DIR__.'/../Http/routes-app.php');
    }
}

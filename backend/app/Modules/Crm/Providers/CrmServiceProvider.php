<?php

declare(strict_types=1);

namespace App\Modules\Crm\Providers;

use App\Modules\Crm\Application\GravarTimelineDoNegocio;
use App\Modules\Crm\Domain\Events\NegocioCriado;
use App\Modules\Crm\Domain\Events\NegocioGanho;
use App\Modules\Crm\Domain\Events\NegocioMovido;
use App\Modules\Crm\Domain\Events\NegocioPerdido;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

final class CrmServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Event::listen(NegocioCriado::class, [GravarTimelineDoNegocio::class, 'criado']);
        Event::listen(NegocioMovido::class, [GravarTimelineDoNegocio::class, 'movido']);
        Event::listen(NegocioGanho::class, [GravarTimelineDoNegocio::class, 'ganho']);
        Event::listen(NegocioPerdido::class, [GravarTimelineDoNegocio::class, 'perdido']);

        $rotas = __DIR__.'/../Http/routes-app.php';

        if (is_file($rotas)) {
            Route::middleware('api')->prefix('api/app/crm')->group($rotas);
        }
    }
}

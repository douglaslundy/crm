<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Providers;

use App\Modules\Fiscal\Application\ContadorDeDocumentosMes;
use App\Modules\Fiscal\Console\ReconciliarNotasCommand;
use App\Modules\Fiscal\Domain\Contracts\EmissorDeNfe;
use App\Modules\Platform\Application\RegistroDeContadores;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use LogicException;

final class FiscalServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Provisório: a Tarefa 14 troca pelo NfePhpEmissor. Os testes sobrescrevem com app()->instance().
        $this->app->bind(EmissorDeNfe::class, fn () => throw new LogicException('Emissor NFePHP ainda não configurado.'));
    }

    public function boot(): void
    {
        $this->app->make(RegistroDeContadores::class)->registrar(new ContadorDeDocumentosMes);

        if ($this->app->runningInConsole()) {
            $this->commands([ReconciliarNotasCommand::class]);
        }

        Route::middleware('api')->prefix('api/app')->group(__DIR__.'/../Http/routes-app.php');
    }
}

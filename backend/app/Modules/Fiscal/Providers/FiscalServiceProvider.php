<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Providers;

use App\Modules\Fiscal\Application\ContadorDeDocumentosMes;
use App\Modules\Fiscal\Console\ReconciliarNotasCommand;
use App\Modules\Fiscal\Console\RetransmitirContingenciaCommand;
use App\Modules\Fiscal\Domain\Contracts\EmissorDeNfe;
use App\Modules\Fiscal\Domain\Contracts\EmissorDeNfse;
use App\Modules\Fiscal\Domain\Contracts\GeradorDeDanfe;
use App\Modules\Fiscal\Infrastructure\NfePhp\NfePhpEmissor;
use App\Modules\Fiscal\Infrastructure\Nfse\NfseNacionalEmissor;
use App\Modules\Fiscal\Infrastructure\Pdf\SpedDaGeradorDeDanfe;
use App\Modules\Platform\Application\RegistroDeContadores;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

final class FiscalServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Os testes sobrescrevem com app()->instance(EmissorDeNfe::class, new EmissorFake).
        $this->app->bind(EmissorDeNfe::class, NfePhpEmissor::class);
        $this->app->bind(EmissorDeNfse::class, NfseNacionalEmissor::class);
        $this->app->bind(GeradorDeDanfe::class, SpedDaGeradorDeDanfe::class);
    }

    public function boot(): void
    {
        $this->app->make(RegistroDeContadores::class)->registrar(new ContadorDeDocumentosMes);

        if ($this->app->runningInConsole()) {
            $this->commands([ReconciliarNotasCommand::class, RetransmitirContingenciaCommand::class]);
        }

        Route::middleware('api')->prefix('api/app')->group(__DIR__.'/../Http/routes-app.php');
    }
}

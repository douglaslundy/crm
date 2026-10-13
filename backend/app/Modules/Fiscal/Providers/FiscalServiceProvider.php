<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Providers;

use App\Modules\Crm\Domain\Contracts\GeradorDeRascunhoDeNota;
use App\Modules\Fiscal\Application\ContadorDeDocumentosMes;
use App\Modules\Fiscal\Application\GeradorDeRascunhoParaCrm;
use App\Modules\Fiscal\Application\Webhooks\DespachanteDeEntrega;
use App\Modules\Fiscal\Application\Webhooks\DespachanteDeEntregaPorJob;
use App\Modules\Fiscal\Application\Webhooks\ResolvedorDeDns;
use App\Modules\Fiscal\Application\Webhooks\ResolvedorDeDnsDoSistema;
use App\Modules\Fiscal\Console\LimparWebhooksCommand;
use App\Modules\Fiscal\Console\ReconciliarNotasCommand;
use App\Modules\Fiscal\Console\ReenviarWebhooksPendentesCommand;
use App\Modules\Fiscal\Console\RetransmitirContingenciaCommand;
use App\Modules\Fiscal\Domain\Contracts\EmissorDeNfe;
use App\Modules\Fiscal\Domain\Contracts\EmissorDeNfse;
use App\Modules\Fiscal\Domain\Contracts\GeradorDeDanfe;
use App\Modules\Fiscal\Domain\Contracts\GeradorDeDanfse;
use App\Modules\Fiscal\Domain\Models\NotaEvento;
use App\Modules\Fiscal\Infrastructure\NfePhp\NfePhpEmissor;
use App\Modules\Fiscal\Infrastructure\Nfse\NfseNacionalEmissor;
use App\Modules\Fiscal\Infrastructure\ObservadorDeNotaEvento;
use App\Modules\Fiscal\Infrastructure\Pdf\DompdfGeradorDeDanfse;
use App\Modules\Fiscal\Infrastructure\Pdf\SpedDaGeradorDeDanfe;
use App\Modules\Platform\Application\RegistroDeContadores;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

final class FiscalServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(GeradorDeRascunhoDeNota::class, GeradorDeRascunhoParaCrm::class);
        // Os testes sobrescrevem com app()->instance(EmissorDeNfe::class, new EmissorFake).
        $this->app->bind(EmissorDeNfe::class, NfePhpEmissor::class);
        $this->app->bind(EmissorDeNfse::class, NfseNacionalEmissor::class);
        $this->app->bind(GeradorDeDanfe::class, SpedDaGeradorDeDanfe::class);
        $this->app->bind(GeradorDeDanfse::class, DompdfGeradorDeDanfse::class);
        $this->app->bind(ResolvedorDeDns::class, ResolvedorDeDnsDoSistema::class);
        $this->app->bind(DespachanteDeEntrega::class, DespachanteDeEntregaPorJob::class);
    }

    public function boot(): void
    {
        $this->app->make(RegistroDeContadores::class)->registrar(new ContadorDeDocumentosMes);
        NotaEvento::observe(ObservadorDeNotaEvento::class);

        if ($this->app->runningInConsole()) {
            $this->commands([
                ReconciliarNotasCommand::class, RetransmitirContingenciaCommand::class,
                ReenviarWebhooksPendentesCommand::class, LimparWebhooksCommand::class,
            ]);
        }

        Route::middleware('api')->prefix('api/app')->group(__DIR__.'/../Http/routes-app.php');
        Route::middleware('api')->prefix('api/v1')->group(__DIR__.'/../Http/routes-v1.php');
    }
}

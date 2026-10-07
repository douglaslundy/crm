<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Application\Jobs;

use App\Modules\Fiscal\Application\Actions\AplicarResultadoDaNfse;
use App\Modules\Fiscal\Domain\Contracts\EmissorDeNfse;
use App\Modules\Fiscal\Domain\Emissao\NotaParaNfse;
use App\Modules\Fiscal\Domain\Emissao\ResultadoDeEmissaoNfse;
use App\Modules\Fiscal\Domain\Enums\SituacaoNaSefaz;
use App\Modules\Fiscal\Domain\Enums\StatusNota;
use App\Modules\Fiscal\Domain\Models\Emitente;
use App\Modules\Fiscal\Domain\Models\Nota;
use App\Modules\Tenancy\Domain\Models\Tenant;
use App\Modules\Tenancy\Domain\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

final class EmitirNfseJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 1;

    public int $timeout = 180;

    public function __construct(public readonly string $notaId, public readonly string $tenantId) {}

    public function handle(EmissorDeNfse $emissor, AplicarResultadoDaNfse $aplicar, TenantContext $contexto): void
    {
        // Num worker o contexto anterior é nulo; com fila `sync` restauramos o do chamador.
        $anterior = $contexto->id();
        $contexto->set($this->tenantId);
        try {
            $nota = Nota::query()->find($this->notaId);
            if ($nota === null || $nota->status !== StatusNota::Processando) {
                return;
            }
            $emitente = Emitente::query()->findOrFail($nota->emitente_id);
            $dto = NotaParaNfse::deNota($nota, $emitente, Tenant::query()->findOrFail($nota->tenant_id));

            // Idempotência: a DPS pode já existir na ADN (resposta perdida). Incerteza NUNCA reenvia.
            $consulta = $emissor->consultarDps($emitente, $dto);
            match ($consulta->situacao) {
                SituacaoNaSefaz::Autorizada, SituacaoNaSefaz::Cancelada => $aplicar->aplicarConsulta($nota->id, $consulta),
                SituacaoNaSefaz::Erro => $aplicar->executar($nota->id, ResultadoDeEmissaoNfse::erro(
                    ($consulta->mensagem ?? 'Não foi possível confirmar se a DPS já existe na ADN.').' Tente de novo: o número foi preservado.',
                )),
                SituacaoNaSefaz::NaoEncontrada => $this->enviar($nota->id, $emissor, $aplicar, $emitente, $dto),
            };
        } catch (Throwable $e) {
            report($e);
            $aplicar->executar($this->notaId, ResultadoDeEmissaoNfse::erro('Falha técnica ao emitir: '.mb_substr($e->getMessage(), 0, 300)));
        } finally {
            $contexto->set($anterior);
        }
    }

    /**
     * Envia. Se a ADN recusar, pergunta de novo pela DPS: recusa por DPS duplicada (a ADN ainda processava um envio
     * anterior) não pode virar REJEITADA, porque a nota existe lá.
     */
    private function enviar(string $notaId, EmissorDeNfse $emissor, AplicarResultadoDaNfse $aplicar, Emitente $emitente, NotaParaNfse $dto): void
    {
        $resultado = $emissor->emitir($emitente, $dto);
        if ($resultado->status === StatusNota::Rejeitada) {
            $reconsulta = $emissor->consultarDps($emitente, $dto);
            if (in_array($reconsulta->situacao, [SituacaoNaSefaz::Autorizada, SituacaoNaSefaz::Cancelada], true)) {
                $aplicar->aplicarConsulta($notaId, $reconsulta);

                return;
            }
        }
        $aplicar->executar($notaId, $resultado);
    }
}

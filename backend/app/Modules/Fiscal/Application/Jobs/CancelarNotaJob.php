<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Application\Jobs;

use App\Modules\Fiscal\Application\Actions\AplicarResultadoDoCancelamento;
use App\Modules\Fiscal\Domain\Contracts\EmissorDeNfe;
use App\Modules\Fiscal\Domain\Contracts\EmissorDeNfse;
use App\Modules\Fiscal\Domain\Enums\ModeloDocumento;
use App\Modules\Fiscal\Domain\Enums\StatusNota;
use App\Modules\Fiscal\Domain\Models\Emitente;
use App\Modules\Fiscal\Domain\Models\Nota;
use App\Modules\Tenancy\Domain\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

final class CancelarNotaJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 1;

    public int $timeout = 180;

    public function __construct(public readonly string $notaId, public readonly string $tenantId) {}

    public function handle(EmissorDeNfe $emissor, AplicarResultadoDoCancelamento $aplicar, TenantContext $contexto, EmissorDeNfse $emissorNfse): void
    {
        // Num worker o contexto anterior é nulo; com fila `sync` restauramos o do chamador.
        $anterior = $contexto->id();
        $contexto->set($this->tenantId);
        try {
            $nota = Nota::query()->find($this->notaId);
            if ($nota === null || $nota->status !== StatusNota::Cancelando) {
                return;
            }
            $emitente = Emitente::query()->findOrFail($nota->emitente_id);
            $nota->makeVisible('xml_autorizado');

            $resultado = $nota->modelo === ModeloDocumento::Dps
                ? $emissorNfse->cancelar($emitente, (string) $nota->chave, (string) $nota->cancelamento_justificativa, (string) $nota->ambiente)
                : $emissor->cancelar(
                    $emitente, (string) $nota->chave, (string) $nota->protocolo, (string) $nota->cancelamento_justificativa,
                    (string) $nota->ambiente, (string) $nota->xml_autorizado,
                );
            $aplicar->executar($nota->id, $resultado);
        } catch (Throwable $e) {
            report($e);
            $aplicar->registrarFalhaTecnica($this->notaId, $e->getMessage());
        } finally {
            $contexto->set($anterior);
        }
    }
}

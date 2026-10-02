<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Application\Jobs;

use App\Modules\Fiscal\Application\Actions\AplicarResultadoDaEmissao;
use App\Modules\Fiscal\Domain\Contracts\EmissorDeNfe;
use App\Modules\Fiscal\Domain\Emissao\NotaParaEmissao;
use App\Modules\Fiscal\Domain\Emissao\ResultadoDeEmissao;
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

final class EmitirNotaJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 1;

    public int $timeout = 180;

    public function __construct(public readonly string $notaId, public readonly string $tenantId) {}

    public function handle(EmissorDeNfe $emissor, AplicarResultadoDaEmissao $aplicar, TenantContext $contexto): void
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

            if ($nota->chave !== null) {
                $consulta = $emissor->consultarPorChave($emitente, $nota->chave, $nota->xml_enviado, (string) $nota->ambiente);
                if ($consulta->situacao === SituacaoNaSefaz::Autorizada) {
                    $aplicar->aplicarConsulta($nota->id, $consulta);

                    return;
                }
                if ($consulta->situacao === SituacaoNaSefaz::Cancelada) {
                    $aplicar->executar($nota->id, ResultadoDeEmissao::erro("A chave {$nota->chave} consta como cancelada na SEFAZ. Edite e emita novamente."));

                    return;
                }
            }

            $tenant = Tenant::query()->findOrFail($nota->tenant_id);
            $dto = NotaParaEmissao::deNota($nota, $emitente, $tenant);
            $xml = $emissor->preparar($emitente, $dto);
            $nota->forceFill(['chave' => $xml->chave, 'xml_enviado' => $xml->xml])->save();

            $aplicar->executar($nota->id, $emissor->transmitir($emitente, $dto, $xml));
        } catch (Throwable $e) {
            report($e);
            $aplicar->executar($this->notaId, ResultadoDeEmissao::erro('Falha técnica ao emitir: '.mb_substr($e->getMessage(), 0, 300)));
        } finally {
            $contexto->set($anterior);
        }
    }
}

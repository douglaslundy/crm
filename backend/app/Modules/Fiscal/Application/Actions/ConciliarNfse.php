<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Application\Actions;

use App\Modules\Fiscal\Domain\Contracts\EmissorDeNfse;
use App\Modules\Fiscal\Domain\Emissao\NotaParaNfse;
use App\Modules\Fiscal\Domain\Emissao\ResultadoDeEmissaoNfse;
use App\Modules\Fiscal\Domain\Enums\SituacaoNaSefaz;
use App\Modules\Fiscal\Domain\Enums\StatusNota;
use App\Modules\Fiscal\Domain\Models\Emitente;
use App\Modules\Fiscal\Domain\Models\Nota;
use App\Modules\Tenancy\Domain\Models\Tenant;

/** Reconciliação da NFS-e com a ADN. Incerteza (consulta com erro) nunca muda o status. */
final class ConciliarNfse
{
    public function __construct(
        private readonly EmissorDeNfse $emissor,
        private readonly AplicarResultadoDaNfse $aplicar,
        private readonly AplicarResultadoDoCancelamento $cancelamento,
    ) {}

    public function executar(Nota $nota): Nota
    {
        if ($nota->status === StatusNota::Cancelando) {
            return $this->conciliarCancelamento($nota);
        }
        if ($nota->status !== StatusNota::Processando) {
            return $nota;
        }

        $vencida = $nota->iniciada_em === null
            || $nota->iniciada_em->lt(now()->subMinutes((int) config('fiscal.minutos_para_nunca_enviada')));
        $emitente = Emitente::query()->findOrFail($nota->emitente_id);
        $dto = NotaParaNfse::deNota($nota, $emitente, Tenant::query()->findOrFail($nota->tenant_id));

        $consulta = $this->emissor->consultarDps($emitente, $dto);

        return match ($consulta->situacao) {
            SituacaoNaSefaz::Autorizada, SituacaoNaSefaz::Cancelada => $this->aplicar->aplicarConsulta($nota->id, $consulta),
            SituacaoNaSefaz::NaoEncontrada => $vencida
                ? $this->aplicar->executar($nota->id, ResultadoDeEmissaoNfse::erro('A ADN não recebeu a DPS. Emita novamente: o número foi preservado.'))
                : $nota,
            SituacaoNaSefaz::Erro => $nota,
        };
    }

    private function conciliarCancelamento(Nota $nota): Nota
    {
        $emitente = Emitente::query()->findOrFail($nota->emitente_id);
        $consulta = $this->emissor->consultarPorChave($emitente, (string) $nota->chave, (string) $nota->ambiente);
        $vencida = $nota->cancelamento_solicitado_em === null
            || $nota->cancelamento_solicitado_em->lt(now()->subMinutes((int) config('fiscal.minutos_para_nunca_enviada')));

        return match ($consulta->situacao) {
            SituacaoNaSefaz::Cancelada => $this->cancelamento->confirmarCancelamento($nota->id, 'Cancelamento confirmado na ADN'),
            SituacaoNaSefaz::Autorizada => $vencida ? $this->cancelamento->naoConfirmado($nota->id) : $nota,
            SituacaoNaSefaz::NaoEncontrada, SituacaoNaSefaz::Erro => $nota,
        };
    }
}

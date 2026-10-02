<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Application\Actions;

use App\Modules\Fiscal\Domain\Contracts\EmissorDeNfe;
use App\Modules\Fiscal\Domain\Emissao\ResultadoDeEmissao;
use App\Modules\Fiscal\Domain\Enums\SituacaoNaSefaz;
use App\Modules\Fiscal\Domain\Enums\StatusNota;
use App\Modules\Fiscal\Domain\Models\Emitente;
use App\Modules\Fiscal\Domain\Models\Nota;

final class ConciliarNota
{
    public function __construct(private readonly EmissorDeNfe $emissor, private readonly AplicarResultadoDaEmissao $aplicar) {}

    public function executar(Nota $nota): Nota
    {
        if ($nota->status !== StatusNota::Processando) {
            return $nota;
        }

        $vencida = $nota->iniciada_em === null
            || $nota->iniciada_em->lt(now()->subMinutes((int) config('fiscal.minutos_para_nunca_enviada')));

        if ($nota->chave === null) {
            return $vencida
                ? $this->aplicar->executar($nota->id, ResultadoDeEmissao::erro('A emissão não chegou a ser enviada à SEFAZ. Emita novamente: o número foi preservado.'))
                : $nota;
        }

        $emitente = Emitente::query()->findOrFail($nota->emitente_id);
        $consulta = $this->emissor->consultarPorChave($emitente, $nota->chave, $nota->xml_enviado);

        return match ($consulta->situacao) {
            SituacaoNaSefaz::Autorizada, SituacaoNaSefaz::Cancelada => $this->aplicar->aplicarConsulta($nota->id, $consulta),
            SituacaoNaSefaz::NaoEncontrada => $vencida
                ? $this->aplicar->executar($nota->id, ResultadoDeEmissao::erro('A SEFAZ não recebeu a nota. Emita novamente: o número foi preservado.'))
                : $nota,
            SituacaoNaSefaz::Erro => $nota,
        };
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Application\Actions;

use App\Modules\Fiscal\Domain\Contracts\EmissorDeNfe;
use App\Modules\Fiscal\Domain\Emissao\ResultadoDeConsulta;
use App\Modules\Fiscal\Domain\Enums\SituacaoNaSefaz;
use App\Modules\Fiscal\Domain\Exceptions\FalhaDeComunicacaoException;
use App\Modules\Fiscal\Domain\Models\Emitente;
use App\Modules\Fiscal\Domain\Models\Nota;

/** Antes de entrar em contingência: a SEFAZ pode já ter a nota (resposta perdida no caminho). Nunca duplicar. */
final class ConsultarChaveOriginal
{
    public function __construct(private readonly EmissorDeNfe $emissor) {}

    /**
     * @return ResultadoDeConsulta|null a consulta, só quando a SEFAZ já tem a nota (autorizada ou cancelada);
     *                                  nulo quando não há chave, não foi encontrada, deu erro ou a consulta também falhou por comunicação.
     */
    public function executar(Emitente $emitente, Nota $nota): ?ResultadoDeConsulta
    {
        if ($nota->chave === null) {
            return null;
        }
        try {
            $consulta = $this->emissor->consultarPorChave($emitente, $nota->chave, $nota->xml_enviado, (string) $nota->ambiente);
        } catch (FalhaDeComunicacaoException) {
            return null;
        }

        return in_array($consulta->situacao, [SituacaoNaSefaz::Autorizada, SituacaoNaSefaz::Cancelada], true) ? $consulta : null;
    }
}

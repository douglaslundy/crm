<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Domain\Contracts;

use App\Modules\Fiscal\Domain\Emissao\NotaParaEmissao;
use App\Modules\Fiscal\Domain\Emissao\ResultadoDeConsulta;
use App\Modules\Fiscal\Domain\Emissao\ResultadoDeEmissao;
use App\Modules\Fiscal\Domain\Emissao\XmlAssinado;
use App\Modules\Fiscal\Domain\Models\Emitente;

/** O motor só traduz formato: nenhuma regra tributária mora aqui. */
interface EmissorDeNfe
{
    /** Monta e assina o XML. Sem rede: a chave pode ser gravada antes de qualquer envio. */
    public function preparar(Emitente $emitente, NotaParaEmissao $nota): XmlAssinado;

    /** Envia e interpreta a resposta. Falha de comunicação lança exceção (vira ERRO no job). */
    public function transmitir(Emitente $emitente, NotaParaEmissao $nota, XmlAssinado $xml): ResultadoDeEmissao;

    public function consultarPorChave(Emitente $emitente, string $chave, ?string $xmlEnviado): ResultadoDeConsulta;
}

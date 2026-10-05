<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Domain\Contracts;

use App\Modules\Fiscal\Domain\Emissao\NotaParaEmissao;
use App\Modules\Fiscal\Domain\Emissao\ResultadoDeCancelamento;
use App\Modules\Fiscal\Domain\Emissao\ResultadoDeConsulta;
use App\Modules\Fiscal\Domain\Emissao\ResultadoDeEmissao;
use App\Modules\Fiscal\Domain\Emissao\ResultadoDeInutilizacao;
use App\Modules\Fiscal\Domain\Emissao\XmlAssinado;
use App\Modules\Fiscal\Domain\Enums\ModeloDocumento;
use App\Modules\Fiscal\Domain\Models\Emitente;
use Carbon\CarbonImmutable;

/** O motor só traduz formato: nenhuma regra tributária mora aqui. */
interface EmissorDeNfe
{
    /** Monta e assina o XML. Sem rede: a chave pode ser gravada antes de qualquer envio. */
    public function preparar(Emitente $emitente, NotaParaEmissao $nota): XmlAssinado;

    /**
     * Só NFC-e: remonta com `tpEmis=9` (offline), `dhCont` e `xJust`, com chave nova, e assina. Sem rede.
     * A biblioteca não faz isso para o modelo 65; o XML é remontado pelo montador.
     */
    public function prepararContingencia(Emitente $emitente, NotaParaEmissao $nota, string $justificativa, CarbonImmutable $em): XmlAssinado;

    /** Envia e interpreta a resposta. Falha de comunicação lança `FalhaDeComunicacaoException` (NFC-e vai para contingência; NF-e vira ERRO no job). */
    public function transmitir(Emitente $emitente, NotaParaEmissao $nota, XmlAssinado $xml): ResultadoDeEmissao;

    /** `$ambiente` é o da NOTA (HOMOLOGACAO ou PRODUCAO), nunca o atual do emitente: ele pode ter mudado depois da emissão. */
    public function consultarPorChave(Emitente $emitente, string $chave, ?string $xmlEnviado, string $ambiente): ResultadoDeConsulta;

    /** Envia o evento de cancelamento. Falha de comunicação lança exceção (a nota permanece CANCELANDO). */
    public function cancelar(Emitente $emitente, string $chave, string $protocolo, string $justificativa, string $ambiente, string $xmlAutorizado): ResultadoDeCancelamento;

    public function inutilizar(Emitente $emitente, ModeloDocumento $modelo, int $serie, int $numeroInicial, int $numeroFinal, string $justificativa, string $ambiente): ResultadoDeInutilizacao;
}

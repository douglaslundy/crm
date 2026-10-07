<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Domain\Contracts;

use App\Modules\Fiscal\Domain\Emissao\NotaParaNfse;
use App\Modules\Fiscal\Domain\Emissao\ResultadoDeCancelamento;
use App\Modules\Fiscal\Domain\Emissao\ResultadoDeConsultaNfse;
use App\Modules\Fiscal\Domain\Emissao\ResultadoDeEmissaoNfse;
use App\Modules\Fiscal\Domain\Models\Emitente;

/** O motor só traduz formato: nenhuma regra tributária mora aqui. A NFS-e não tem contingência. */
interface EmissorDeNfse
{
    /**
     * A DPS já existe na ADN? É a defesa contra duplicata (resposta perdida): nunca reenviar sem perguntar.
     * `Erro` quer dizer incerteza: quem chama NÃO reenvia.
     */
    public function consultarDps(Emitente $emitente, NotaParaNfse $nota): ResultadoDeConsultaNfse;

    /** Assina, envia e interpreta. Rejeição da ADN volta em `rejeitada`; falha técnica volta em `erro`. */
    public function emitir(Emitente $emitente, NotaParaNfse $nota): ResultadoDeEmissaoNfse;

    /** `$chave` com 50 dígitos, sem o prefixo `NFS`. A lib não distingue "não encontrada" de falha: nulo vira `Erro`. */
    public function consultarPorChave(Emitente $emitente, string $chave, string $ambiente): ResultadoDeConsultaNfse;

    /** Evento 101101. Recusa da ADN volta em `recusado`; falha de rede lança exceção (a nota permanece CANCELANDO). */
    public function cancelar(Emitente $emitente, string $chave, string $justificativa, string $ambiente): ResultadoDeCancelamento;
}

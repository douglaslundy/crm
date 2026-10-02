<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Domain\Emissao;

use App\Modules\Fiscal\Domain\Enums\StatusInutilizacao;

final readonly class ResultadoDeInutilizacao
{
    public function __construct(
        public StatusInutilizacao $status,
        public ?string $cstat,
        public ?string $motivo,
        public ?string $protocolo,
        public ?string $xmlResposta,
    ) {}

    public static function inutilizada(string $protocolo, string $xmlResposta, string $cstat = '102'): self
    {
        return new self(StatusInutilizacao::Inutilizada, $cstat, 'Inutilização de número homologado', $protocolo, $xmlResposta);
    }

    public static function recusada(string $cstat, string $motivo): self
    {
        return new self(StatusInutilizacao::Recusada, $cstat, $motivo, null, null);
    }

    public static function jaInutilizada(string $motivo): self
    {
        return new self(StatusInutilizacao::Recusada, '563', 'Esta faixa já foi inutilizada na SEFAZ. ('.$motivo.')', null, null);
    }

    public static function erro(string $mensagem): self
    {
        return new self(StatusInutilizacao::Erro, null, $mensagem, null, null);
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Domain\Emissao;

final readonly class ResultadoDeCancelamento
{
    public function __construct(
        public bool $aceito,
        public ?string $cstat,
        public ?string $motivo,
        public ?string $protocolo,
        public ?string $xmlCancelamento,
    ) {}

    public static function aceito(string $protocolo, string $xmlCancelamento, string $cstat = '135'): self
    {
        return new self(true, $cstat, 'Cancelamento de NF-e homologado', $protocolo, $xmlCancelamento);
    }

    public static function recusado(string $cstat, string $motivo): self
    {
        return new self(false, $cstat, $motivo, null, null);
    }
}

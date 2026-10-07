<?php

declare(strict_types=1);

namespace App\Modules\Billing\Domain\Gateway;

use Carbon\CarbonImmutable;

final readonly class DadosDoBoleto
{
    public function __construct(
        public string $cobrancaId,
        public int $valorCentavos,
        public string $descricao,
        public string $emailDoPagador,
        public string $nomeDoPagador,
        public string $documento,
        public CarbonImmutable $venceEm,
        public string $idempotencia,
    ) {}
}

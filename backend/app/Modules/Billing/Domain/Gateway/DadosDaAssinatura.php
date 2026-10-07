<?php

declare(strict_types=1);

namespace App\Modules\Billing\Domain\Gateway;

/** Assinatura recorrente no cartão. Valor e descrição vêm sempre do servidor. */
final readonly class DadosDaAssinatura
{
    public function __construct(
        public string $cobrancaId,
        public int $valorCentavos,
        public string $descricao,
        public int $meses,
        public string $emailDoPagador,
        public string $tokenDoCartao,
        public string $idempotencia,
    ) {}
}

<?php

declare(strict_types=1);

namespace App\Modules\Billing\Domain\Gateway;

/** Pagamento avulso no cartão, sempre em 1 parcela, com o token do Card Payment Brick. */
final readonly class DadosDoPagamentoDeCartao
{
    public function __construct(
        public string $cobrancaId,
        public int $valorCentavos,
        public string $descricao,
        public string $emailDoPagador,
        public string $nomeDoPagador,
        public string $documento,
        public string $tokenDoCartao,
        public string $idempotencia,
    ) {}
}

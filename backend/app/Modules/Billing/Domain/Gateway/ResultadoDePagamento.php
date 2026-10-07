<?php

declare(strict_types=1);

namespace App\Modules\Billing\Domain\Gateway;

/** O pagamento como o gateway o vê. É a única fonte de status confiável: o corpo do webhook nunca vale. */
final readonly class ResultadoDePagamento
{
    public function __construct(
        public string $id,
        public StatusDoPagamento $status,
        public ?string $referenciaExterna,
        public int $valorCentavos,
        public ?string $assinaturaGatewayId = null,
        public ?string $boletoUrl = null,
        public ?string $linhaDigitavel = null,
    ) {}
}

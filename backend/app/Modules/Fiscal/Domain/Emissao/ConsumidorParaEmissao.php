<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Domain\Emissao;

/** Consumidor identificado da NFC-e: só documento e nome, sem endereço. Venda sem identificação não tem este objeto. */
final readonly class ConsumidorParaEmissao
{
    public function __construct(
        public string $documento,
        public string $nome,
    ) {}
}

<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Domain\Emissao;

final readonly class ItemParaEmissao
{
    public function __construct(
        public int $ordem,
        public string $sku,
        public string $descricao,
        public string $unidade,
        public ?string $gtin,
        public string $ncm,
        public ?string $cest,
        public string $cfop,
        public int $origem,
        public string $csosn,
        public int $quantidadeMilesimos,
        public int $valorUnitarioCentavos,
        public int $descontoCentavos,
        public int $totalCentavos,
    ) {}
}

<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Domain\Emissao;

final readonly class PagamentoParaEmissao
{
    public function __construct(
        public string $tpag,
        public ?string $xpag,
        public int $valorCentavos,
    ) {}
}

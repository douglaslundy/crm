<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Domain\Emissao;

final readonly class ServicoParaNfse
{
    public function __construct(
        public string $cTribNac,
        public ?string $cTribMun,
        public string $descricao,
        public string $aliquotaIss,
        public ?string $nbs,
    ) {}
}

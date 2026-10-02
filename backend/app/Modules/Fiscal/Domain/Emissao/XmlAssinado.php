<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Domain\Emissao;

final readonly class XmlAssinado
{
    public function __construct(
        public string $chave,
        public string $xml,
    ) {}
}

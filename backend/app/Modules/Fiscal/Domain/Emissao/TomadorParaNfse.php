<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Domain\Emissao;

final readonly class TomadorParaNfse
{
    public function __construct(public string $documento, public string $nome) {}
}

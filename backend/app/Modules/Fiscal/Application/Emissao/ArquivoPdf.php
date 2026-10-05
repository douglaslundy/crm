<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Application\Emissao;

final readonly class ArquivoPdf
{
    public function __construct(public string $nome, public string $conteudo) {}
}

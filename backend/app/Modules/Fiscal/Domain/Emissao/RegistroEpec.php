<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Domain\Emissao;

use Carbon\CarbonInterface;

/** Protocolo e data do evento EPEC, que o DANFE da NF-e em contingência imprime como "número de registro". */
final readonly class RegistroEpec
{
    public function __construct(public string $protocolo, public CarbonInterface $registradoEm) {}
}

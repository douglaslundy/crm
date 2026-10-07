<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Domain\Emissao;

final readonly class EmitenteParaNfse
{
    /** @param  string  $regime  'SIMPLES' ou 'MEI' (o Regime Normal é bloqueado antes). */
    public function __construct(public string $cnpj, public string $codigoIbge, public string $regime) {}
}

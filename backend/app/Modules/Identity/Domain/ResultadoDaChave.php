<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain;

use App\Modules\Identity\Domain\Models\ChaveDeApi;

/** O texto da chave só existe neste retorno: nunca é gravado nem registrado. */
final readonly class ResultadoDaChave
{
    public function __construct(public ChaveDeApi $modelo, public string $texto) {}
}

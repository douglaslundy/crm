<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Domain\Contracts;

/** Desenha o auxiliar impresso a partir do XML. Nenhuma regra tributária mora aqui. */
interface GeradorDeDanfe
{
    /** @return string bytes do PDF */
    public function gerar(string $xml, bool $cancelada): string;
}

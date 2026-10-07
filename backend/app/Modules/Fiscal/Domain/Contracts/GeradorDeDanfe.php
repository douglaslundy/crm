<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Domain\Contracts;

use App\Modules\Fiscal\Domain\Emissao\RegistroEpec;
use App\Modules\Fiscal\Domain\Enums\ModeloDocumento;

/** Desenha o auxiliar impresso a partir do XML. Nenhuma regra tributária mora aqui. */
interface GeradorDeDanfe
{
    /** NF-e vira DANFE A4; NFC-e vira o cupom de 80 mm. Com `$epec`, o DANFE traz o registro do EPEC. @return string bytes do PDF */
    public function gerar(string $xml, bool $cancelada, ModeloDocumento $modelo = ModeloDocumento::Nfe, ?RegistroEpec $epec = null): string;
}

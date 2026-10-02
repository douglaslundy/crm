<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Domain\Resolvers;

use App\Modules\Catalog\Domain\Enums\TributacaoIcms;
use App\Modules\Fiscal\Domain\TabelaIbge;

final class CfopSaidaResolver
{
    public function resolver(string $ufOrigem, string $ufDestino, TributacaoIcms $tributacao): string
    {
        TabelaIbge::codigoDaUf($ufOrigem);
        TabelaIbge::codigoDaUf($ufDestino);
        $interna = strtoupper($ufOrigem) === strtoupper($ufDestino);

        return match ($tributacao) {
            TributacaoIcms::Normal => $interna ? '5102' : '6102',
            TributacaoIcms::St => $interna ? '5405' : '6404',
        };
    }
}

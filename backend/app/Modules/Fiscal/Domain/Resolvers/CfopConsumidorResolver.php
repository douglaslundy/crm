<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Domain\Resolvers;

use App\Modules\Catalog\Domain\Enums\TributacaoIcms;
use App\Modules\Fiscal\Domain\Exceptions\EmissaoBloqueadaException;
use App\Modules\Fiscal\Domain\TabelaIbge;

/** CFOP da NFC-e: sempre operação interna (sem destinatário identificado também é interna). Nunca chuta 6108. */
final class CfopConsumidorResolver
{
    public function resolver(string $ufEmitente, ?string $ufDestino, TributacaoIcms $tributacao): string
    {
        TabelaIbge::codigoDaUf($ufEmitente);
        if ($ufDestino !== null) {
            TabelaIbge::codigoDaUf($ufDestino);
            if (strtoupper($ufEmitente) !== strtoupper($ufDestino)) {
                throw new EmissaoBloqueadaException('FISCAL_NFCE_INTERESTADUAL', 'A NFC-e não pode ser emitida para outra UF. Use NF-e.');
            }
        }

        return match ($tributacao) {
            TributacaoIcms::Normal => '5102',
            TributacaoIcms::St => '5405',
        };
    }
}

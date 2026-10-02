<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Domain\Resolvers;

use App\Modules\Catalog\Domain\Enums\TributacaoIcms;

/** CSOSN do Simples Nacional: 102 (sem permissão de crédito) e 500 (ST já retido). */
final class TributacaoIcmsSaidaResolver
{
    public function csosn(TributacaoIcms $tributacao): string
    {
        return match ($tributacao) {
            TributacaoIcms::Normal => '102',
            TributacaoIcms::St => '500',
        };
    }
}

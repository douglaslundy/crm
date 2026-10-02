<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Domain\Resolvers;

use App\Modules\Fiscal\Domain\Exceptions\EmissaoBloqueadaException;

/** Fonte única da classificação Simples. MEI é Simples. */
final class CrtResolver
{
    public function crt(?string $regime): int
    {
        return match ($regime) {
            'SIMPLES', 'MEI' => 1,
            'NORMAL' => throw new EmissaoBloqueadaException(
                'FISCAL_REGIME_NAO_SUPORTADO',
                'A emissão para o Regime Normal ainda não está disponível. Por enquanto a plataforma emite para Simples Nacional e MEI.',
            ),
            default => throw new EmissaoBloqueadaException(
                'FISCAL_EMITENTE_INCOMPLETO',
                'Informe o regime tributário em Configurações > Fiscal antes de emitir.',
            ),
        };
    }
}

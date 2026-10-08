<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Application;

use App\Modules\Fiscal\Domain\Enums\AmbienteFiscal;
use App\Modules\Fiscal\Domain\Exceptions\ApiAmbienteDivergenteException;
use App\Modules\Identity\Domain\Enums\AmbienteDaChave;

/** A chave `test_` só opera em homologação e a `live_` só em produção: emitir, cancelar e inutilizar. */
final class VerificadorDeAmbienteDaChave
{
    public function garantir(AmbienteDaChave $chave, AmbienteFiscal $alvo): void
    {
        $casa = ($chave === AmbienteDaChave::Test) === ($alvo === AmbienteFiscal::Homologacao);
        if (! $casa) {
            throw new ApiAmbienteDivergenteException($chave, $alvo);
        }
    }
}

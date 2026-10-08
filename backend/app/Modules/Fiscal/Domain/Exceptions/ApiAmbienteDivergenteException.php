<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Domain\Exceptions;

use App\Modules\Fiscal\Domain\Enums\AmbienteFiscal;
use App\Modules\Identity\Domain\Enums\AmbienteDaChave;
use App\Modules\Shared\Domain\Exceptions\ErroDeNegocio;

/** A chave `test_` só emite com o emitente em homologação e a `live_` só em produção. */
final class ApiAmbienteDivergenteException extends ErroDeNegocio
{
    public function __construct(AmbienteDaChave $chave, AmbienteFiscal $emitente)
    {
        $daChave = $chave === AmbienteDaChave::Test ? 'teste (homologação)' : 'produção';
        $doEmitente = $emitente === AmbienteFiscal::Homologacao ? 'homologação' : 'produção';
        $usar = $emitente === AmbienteFiscal::Homologacao ? 'test_' : 'live_';
        parent::__construct("A chave é de {$daChave}, mas a empresa emite em {$doEmitente}. Use uma chave {$usar} ou mude o ambiente fiscal da empresa.");
    }

    public function status(): int
    {
        return 422;
    }

    public function codigo(): string
    {
        return 'API_AMBIENTE_DIVERGENTE';
    }
}

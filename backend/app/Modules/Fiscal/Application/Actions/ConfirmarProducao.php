<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Application\Actions;

use App\Modules\Fiscal\Domain\Enums\AmbienteFiscal;
use App\Modules\Fiscal\Domain\Models\Emitente;
use App\Modules\Identity\Domain\Models\Usuario;

final class ConfirmarProducao
{
    public function executar(Emitente $emitente, Usuario $autor): Emitente
    {
        $emitente->update(['ambiente_fiscal' => AmbienteFiscal::Producao]);

        activity('fiscal')->performedOn($emitente)->causedBy($autor)->event('ambiente_producao_confirmado')
            ->log('Ambiente fiscal alterado para produção');

        return $emitente;
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Platform\Application\Actions;

use App\Modules\Identity\Domain\Models\Usuario;
use App\Modules\Platform\Domain\Models\Plano;

/** Plano nunca é excluído: sai da venda, e as empresas que o usam continuam nele. */
final class DesativarPlano
{
    public function executar(Plano $plano, Usuario $autor): Plano
    {
        $plano->forceFill(['ativo' => false])->save();

        activity('plataforma')->performedOn($plano)->causedBy($autor)->event('plano_desativado')->log('Plano desativado');

        return $plano->load(['modulos', 'limites'])->loadCount('tenants');
    }
}

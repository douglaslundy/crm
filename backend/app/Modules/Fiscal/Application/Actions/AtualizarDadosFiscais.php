<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Application\Actions;

use App\Modules\Fiscal\Domain\Models\Emitente;

final class AtualizarDadosFiscais
{
    /** @param array{regime_tributario:?string,inscricao_estadual:?string,inscricao_municipal:?string,cnae:?string} $dados */
    public function executar(Emitente $emitente, array $dados): Emitente
    {
        $emitente->update($dados);

        return $emitente;
    }
}

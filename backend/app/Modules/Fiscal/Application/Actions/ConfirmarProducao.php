<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Application\Actions;

use App\Modules\Fiscal\Domain\Enums\AmbienteFiscal;
use App\Modules\Fiscal\Domain\Enums\StatusNota;
use App\Modules\Fiscal\Domain\Exceptions\EmissaoBloqueadaException;
use App\Modules\Fiscal\Domain\Models\Emitente;
use App\Modules\Fiscal\Domain\Models\Nota;
use App\Modules\Identity\Domain\Models\Usuario;

final class ConfirmarProducao
{
    public function executar(Emitente $emitente, Usuario $autor): Emitente
    {
        $temTeste = Nota::query()->where('status', StatusNota::Autorizada)->where('ambiente', AmbienteFiscal::Homologacao->value)->exists();
        if (! $temTeste) {
            throw new EmissaoBloqueadaException('FISCAL_SEM_EMISSAO_DE_TESTE', 'Emita ao menos uma NF-e de teste em homologação antes de ir para produção.');
        }

        $emitente->update(['ambiente_fiscal' => AmbienteFiscal::Producao]);

        activity('fiscal')->performedOn($emitente)->causedBy($autor)->event('ambiente_producao_confirmado')
            ->log('Ambiente fiscal alterado para produção');

        return $emitente;
    }
}

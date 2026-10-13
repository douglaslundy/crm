<?php

declare(strict_types=1);

namespace App\Modules\Crm\Application\Actions;

use App\Modules\Crm\Application\PoliticaDoCrm;
use App\Modules\Crm\Domain\Enums\StatusDaProposta;
use App\Modules\Crm\Domain\Exceptions\RegraDoCrmException;
use App\Modules\Crm\Domain\Models\Proposta;
use App\Modules\Identity\Domain\Models\Usuario;
use Illuminate\Support\Facades\DB;

final class CancelarProposta
{
    public function __construct(private readonly PoliticaDoCrm $politica) {}

    public function executar(Usuario $autor, Proposta $proposta): Proposta
    {
        $this->politica->garantirPodeOperarNegocios($autor);

        return DB::transaction(function () use ($proposta): Proposta {
            $proposta = Proposta::query()->lockForUpdate()->findOrFail($proposta->id);
            if (! in_array($proposta->status, [StatusDaProposta::Rascunho, StatusDaProposta::Enviada], true)) {
                throw new RegraDoCrmException('Só rascunho ou proposta enviada pode ser cancelada.', 'PROPOSTA_NAO_CANCELAVEL');
            }

            // Sem token_hash o link público deixa de resolver.
            $proposta->forceFill(['status' => StatusDaProposta::Cancelada, 'token_hash' => null, 'respondida_em' => now()])->save();

            return $proposta->refresh()->load('itens');
        });
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Platform\Application\Actions;

use App\Modules\Identity\Domain\Models\Usuario;
use App\Modules\Platform\Application\EntitlementService;
use App\Modules\Platform\Domain\Exceptions\PlanoIndisponivelException;
use App\Modules\Platform\Domain\Exceptions\PlanoInsuficienteException;
use App\Modules\Platform\Domain\Models\Plano;
use App\Modules\Tenancy\Domain\Models\Tenant;
use Illuminate\Support\Facades\DB;

final class TrocarPlanoDaEmpresa
{
    public function __construct(private readonly EntitlementService $entitlements) {}

    public function executar(Tenant $tenant, Plano $novo, Usuario $autor): Tenant
    {
        if (! $novo->ativo) {
            throw new PlanoIndisponivelException;
        }

        $excessos = $this->entitlements->excessos($tenant, $novo);
        if ($excessos !== []) {
            throw new PlanoInsuficienteException($excessos);
        }

        return DB::transaction(function () use ($tenant, $novo, $autor): Tenant {
            $anterior = $tenant->plano_id;
            $tenant->forceFill(['plano_id' => $novo->id])->save();

            activity('assinatura')
                ->performedOn($tenant)
                ->causedBy($autor)
                ->event('plano_trocado')
                ->withProperties(['de' => $anterior, 'para' => $novo->id])
                ->log('Plano da empresa trocado');

            return $tenant->load('plano');
        });
    }
}

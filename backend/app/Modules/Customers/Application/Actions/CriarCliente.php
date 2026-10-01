<?php

declare(strict_types=1);

namespace App\Modules\Customers\Application\Actions;

use App\Modules\Customers\Domain\Models\Cliente;
use App\Modules\Identity\Domain\Models\Usuario;
use App\Modules\Platform\Application\EntitlementService;
use App\Modules\Platform\Domain\Enums\Recurso;
use App\Modules\Tenancy\Domain\Models\Tenant;
use Illuminate\Support\Facades\DB;

final class CriarCliente
{
    public function __construct(private readonly EntitlementService $entitlements) {}

    /** @param array<string, mixed> $dados */
    public function executar(Usuario $autor, array $dados): Cliente
    {
        return DB::transaction(function () use ($autor, $dados): Cliente {
            /** @var Tenant $tenant */
            $tenant = Tenant::query()->with('plano.limites')->lockForUpdate()->findOrFail($autor->tenant_id);
            $this->entitlements->garantirCapacidade($tenant, Recurso::Clientes);

            return Cliente::create($dados);
        });
    }
}

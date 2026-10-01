<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application\Actions;

use App\Modules\Catalog\Domain\Models\Servico;
use App\Modules\Identity\Domain\Models\Usuario;
use App\Modules\Platform\Application\EntitlementService;
use App\Modules\Platform\Domain\Enums\Recurso;
use App\Modules\Tenancy\Domain\Models\Tenant;
use Illuminate\Support\Facades\DB;

final class CriarServico
{
    public function __construct(private readonly EntitlementService $entitlements) {}

    /** @param array{nome:string,preco_centavos:int,codigo_lc116:?string,c_trib_nac:?string,codigo_municipal:?string,aliquota_iss:?string,nbs:?string} $dados */
    public function executar(Usuario $autor, array $dados): Servico
    {
        return DB::transaction(function () use ($autor, $dados): Servico {
            /** @var Tenant $tenant */
            $tenant = Tenant::query()->with('plano.limites')->lockForUpdate()->findOrFail($autor->tenant_id);
            $this->entitlements->garantirCapacidade($tenant, Recurso::Servicos);

            return Servico::create($dados);
        });
    }
}

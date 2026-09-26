<?php

declare(strict_types=1);

namespace App\Modules\Platform\Http\Controllers\Admin;

use App\Modules\Platform\Application\EntitlementService;
use App\Modules\Platform\Http\Resources\EmpresaResource;
use App\Modules\Tenancy\Domain\Enums\SituacaoAssinatura;
use App\Modules\Tenancy\Domain\Models\Tenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

final class EmpresasController
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $filtros = $request->validate([
            'situacao' => ['nullable', Rule::enum(SituacaoAssinatura::class)],
            'plano_id' => ['nullable', 'uuid'],
            'busca' => ['nullable', 'string', 'max:100'],
        ]);

        $empresas = Tenant::query()
            ->with('plano')
            ->when($filtros['situacao'] ?? null, fn (Builder $q, string $s) => $q->where('situacao', $s))
            ->when($filtros['plano_id'] ?? null, fn (Builder $q, string $p) => $q->where('plano_id', $p))
            ->when($filtros['busca'] ?? null, function (Builder $q, string $busca): void {
                $termo = '%'.mb_strtolower($busca).'%';
                $cnpj = strtoupper((string) preg_replace('/[^0-9A-Za-z]/', '', $busca));
                // lower() + like: o mesmo comportamento no SQLite e no PostgreSQL.
                $q->where(function (Builder $w) use ($termo, $cnpj): void {
                    $w->whereRaw('lower(razao_social) like ?', [$termo])
                        ->orWhereRaw('lower(nome_fantasia) like ?', [$termo]);
                    if ($cnpj !== '') {
                        $w->orWhere('cnpj', 'like', $cnpj.'%');
                    }
                });
            })
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->cursorPaginate(20)
            // through() em vez de deixar o collection() padrão usar mapInto(): mapInto()
            // passa (item, índice) ao construtor, e o índice (int) colidiria com o
            // parâmetro $consumo da EmpresaResource.
            ->through(fn (Tenant $tenant): EmpresaResource => new EmpresaResource($tenant));

        return EmpresaResource::collection($empresas);
    }

    public function show(Tenant $tenant, EntitlementService $entitlements): EmpresaResource
    {
        $tenant->load(['plano.modulos', 'plano.limites']);

        return new EmpresaResource($tenant, $entitlements->consumo($tenant));
    }
}

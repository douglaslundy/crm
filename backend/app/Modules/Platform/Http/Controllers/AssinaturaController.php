<?php

declare(strict_types=1);

namespace App\Modules\Platform\Http\Controllers;

use App\Modules\Billing\Domain\Enums\StatusDaAssinatura;
use App\Modules\Billing\Domain\Models\Assinatura;
use App\Modules\Billing\Http\Resources\AssinaturaDeCobrancaResource;
use App\Modules\Identity\Domain\Models\Usuario;
use App\Modules\Platform\Application\EntitlementService;
use App\Modules\Platform\Http\Resources\PlanoResource;
use App\Modules\Tenancy\Domain\Models\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AssinaturaController
{
    public function __invoke(Request $request, EntitlementService $entitlements): JsonResponse
    {
        /** @var Usuario $usuario */
        $usuario = $request->user();
        /** @var Tenant $tenant */
        $tenant = $usuario->tenant()->with(['plano.modulos', 'plano.limites'])->firstOrFail();

        $assinatura = Assinatura::query()->where('situacao', '!=', StatusDaAssinatura::Cancelada->value)->latest()->first();

        return response()->json(['data' => [
            'situacao' => $tenant->situacao->value,
            'teste_termina_em' => $tenant->teste_termina_em?->toDateString(),
            'plano' => $tenant->plano === null ? null : (new PlanoResource($tenant->plano))->resolve($request),
            'consumo' => $entitlements->consumo($tenant),
            // Assinatura de cobrança (módulo Billing): a mais recente que não foi cancelada, ou null.
            'assinatura' => $assinatura === null ? null : (new AssinaturaDeCobrancaResource($assinatura))->resolve($request),
        ]]);
    }
}

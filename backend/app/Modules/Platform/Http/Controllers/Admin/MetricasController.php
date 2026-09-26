<?php

declare(strict_types=1);

namespace App\Modules\Platform\Http\Controllers\Admin;

use App\Modules\Tenancy\Domain\Enums\SituacaoAssinatura;
use App\Modules\Tenancy\Domain\Models\Tenant;
use Illuminate\Http\JsonResponse;

final class MetricasController
{
    public function __invoke(): JsonResponse
    {
        $contagem = Tenant::query()->toBase()
            ->selectRaw('situacao, count(*) as total')
            ->groupBy('situacao')
            ->pluck('total', 'situacao');

        $porSituacao = [];
        foreach (SituacaoAssinatura::cases() as $situacao) {
            $porSituacao[$situacao->value] = (int) ($contagem[$situacao->value] ?? 0);
        }

        // MRR estimado: soma do preço mensal das empresas que estão pagando (ou deveriam).
        $mrr = (int) Tenant::query()->toBase()
            ->join('planos', 'planos.id', '=', 'tenants.plano_id')
            ->whereIn('tenants.situacao', [SituacaoAssinatura::Ativa->value, SituacaoAssinatura::Inadimplente->value])
            ->sum('planos.preco_mensal_centavos');

        return response()->json(['data' => [
            'por_situacao' => $porSituacao,
            'novas_30_dias' => Tenant::query()->where('created_at', '>=', now()->subDays(30))->count(),
            'mrr_centavos' => $mrr,
        ]]);
    }
}

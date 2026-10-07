<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Http\Controllers;

use App\Modules\Fiscal\Application\ResumoFiscal;
use App\Modules\Identity\Domain\Models\Usuario;
use App\Modules\Platform\Application\EntitlementService;
use App\Modules\Platform\Domain\Enums\Modulo;
use App\Modules\Platform\Domain\Exceptions\ModuloNaoContratadoException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class DashboardFiscalController
{
    public function __invoke(Request $request, EntitlementService $planos, ResumoFiscal $resumo): JsonResponse
    {
        /** @var Usuario $usuario */
        $usuario = $request->user();
        $tenant = $usuario->tenant;
        $temFiscal = $planos->temModulo($tenant, Modulo::FiscalNfe) || $planos->temModulo($tenant, Modulo::FiscalNfce) || $planos->temModulo($tenant, Modulo::FiscalNfse);
        if (! $temFiscal) {
            throw ModuloNaoContratadoException::para(Modulo::FiscalNfe);
        }

        return response()->json(['data' => $resumo->executar()]);
    }
}

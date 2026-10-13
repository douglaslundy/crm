<?php

declare(strict_types=1);

namespace App\Modules\Crm\Http\Controllers;

use App\Modules\Crm\Application\Actions\RelatorioComercial;
use App\Modules\Crm\Application\Relatorio\PeriodoDoRelatorio;
use App\Modules\Crm\Http\Requests\RelatorioRequest;
use App\Modules\Identity\Domain\Models\Usuario;
use Illuminate\Http\JsonResponse;

final class RelatoriosController
{
    public function index(RelatorioRequest $request, RelatorioComercial $relatorio): JsonResponse
    {
        /** @var Usuario $autor */
        $autor = $request->user();
        /** @var array{de?: string|null, ate?: string|null, vendedor_id?: string|null, funil_id?: string|null} $dados */
        $dados = $request->validated();

        $de = $dados['de'] ?? null;
        $ate = $dados['ate'] ?? null;
        $periodo = $de === null || $ate === null ? PeriodoDoRelatorio::doMes(now()) : PeriodoDoRelatorio::entre($de, $ate);

        return response()->json([
            'data' => $relatorio->executar($autor, $periodo, $dados['vendedor_id'] ?? null, $dados['funil_id'] ?? null),
        ]);
    }
}

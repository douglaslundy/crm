<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Http\Controllers\V1;

use App\Modules\Fiscal\Application\Actions\InutilizarNumeracao;
use App\Modules\Fiscal\Application\VerificadorDeAmbienteDaChave;
use App\Modules\Fiscal\Domain\Models\Emitente;
use App\Modules\Fiscal\Domain\Models\Inutilizacao;
use App\Modules\Fiscal\Http\Requests\InutilizarNumeracaoRequest;
use App\Modules\Fiscal\Http\Resources\InutilizacaoResource;
use App\Modules\Identity\Domain\Enums\AmbienteDaChave;
use App\Modules\Identity\Domain\Models\Usuario;
use App\Modules\Shared\Http\PaginadorPorCursor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class InutilizacoesController
{
    public function index(Request $request, PaginadorPorCursor $paginador): JsonResponse
    {
        return response()->json($paginador->paginar(
            Inutilizacao::query(),
            $request,
            fn (Inutilizacao $inutilizacao): array => (new InutilizacaoResource($inutilizacao))->resolve($request),
        ));
    }

    public function __construct(private readonly VerificadorDeAmbienteDaChave $ambientes) {}

    public function store(InutilizarNumeracaoRequest $request, InutilizarNumeracao $acao): JsonResponse
    {
        /** @var Usuario $autor */
        $autor = $request->user();
        /** @var AmbienteDaChave $ambiente */
        $ambiente = $request->attributes->get('ambiente_da_chave');
        $emitente = Emitente::query()->first();
        if ($emitente !== null) {
            $this->ambientes->garantir($ambiente, $emitente->ambiente_fiscal);
        }

        return (new InutilizacaoResource($acao->executar($request->dados(), $autor)))->response()->setStatusCode(201);
    }
}

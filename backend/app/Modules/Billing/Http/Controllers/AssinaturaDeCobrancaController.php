<?php

declare(strict_types=1);

namespace App\Modules\Billing\Http\Controllers;

use App\Modules\Billing\Application\Actions\CancelarAssinatura;
use App\Modules\Billing\Application\Actions\IniciarAssinatura;
use App\Modules\Billing\Application\Actions\ListarCobrancas;
use App\Modules\Billing\Http\Requests\CheckoutRequest;
use App\Modules\Billing\Http\Resources\AssinaturaDeCobrancaResource;
use App\Modules\Billing\Http\Resources\CobrancaResource;
use App\Modules\Identity\Domain\Models\Usuario;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class AssinaturaDeCobrancaController
{
    public function checkout(CheckoutRequest $request, IniciarAssinatura $acao): JsonResponse
    {
        $resultado = $acao->executar(
            $this->autor($request),
            (string) $request->validated('plano_id'),
            $request->ciclo(),
            $request->metodo(),
            $request->validated('token_do_cartao'),
        );

        return response()->json(['data' => [
            'assinatura' => (new AssinaturaDeCobrancaResource($resultado->assinatura))->resolve($request),
            'cobranca' => (new CobrancaResource($resultado->cobranca))->resolve($request),
        ]], 201);
    }

    public function cobrancas(Request $request, ListarCobrancas $acao): AnonymousResourceCollection
    {
        return CobrancaResource::collection($acao->executar($this->autor($request)));
    }

    public function cancelar(Request $request, CancelarAssinatura $acao): JsonResponse
    {
        $assinatura = $acao->executar($this->autor($request));

        return response()->json(['data' => (new AssinaturaDeCobrancaResource($assinatura))->resolve($request)]);
    }

    private function autor(Request $request): Usuario
    {
        /** @var Usuario $autor */
        $autor = $request->user();

        return $autor;
    }
}

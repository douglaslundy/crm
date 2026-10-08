<?php

declare(strict_types=1);

namespace App\Modules\Billing\Http\Controllers;

use App\Modules\Billing\Application\Actions\CancelarAssinatura;
use App\Modules\Billing\Application\Actions\CancelarDowngrade;
use App\Modules\Billing\Application\Actions\IniciarAssinatura;
use App\Modules\Billing\Application\Actions\ListarCobrancas;
use App\Modules\Billing\Application\Actions\PagarCobranca;
use App\Modules\Billing\Application\Actions\TrocarDePlano;
use App\Modules\Billing\Http\Requests\CheckoutRequest;
use App\Modules\Billing\Http\Requests\PagarCobrancaRequest;
use App\Modules\Billing\Http\Requests\TrocarPlanoRequest;
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

    public function pagar(PagarCobrancaRequest $request, PagarCobranca $acao, string $cobranca): JsonResponse
    {
        $paga = $acao->executar($this->autor($request), $cobranca, $request->metodo(), $request->validated('token_do_cartao'));

        return response()->json(['data' => (new CobrancaResource($paga))->resolve($request)]);
    }

    public function trocarPlano(TrocarPlanoRequest $request, TrocarDePlano $acao): JsonResponse
    {
        $troca = $acao->executar($this->autor($request), (string) $request->validated('plano_id'));

        return response()->json(['data' => [
            'tipo' => $troca->tipo->value,
            'cobranca' => $troca->cobranca === null ? null : (new CobrancaResource($troca->cobranca))->resolve($request),
            'assinatura' => (new AssinaturaDeCobrancaResource($troca->assinatura))->resolve($request),
        ]]);
    }

    public function cancelarTroca(Request $request, CancelarDowngrade $acao): JsonResponse
    {
        return response()->json(['data' => (new AssinaturaDeCobrancaResource($acao->executar($this->autor($request))))->resolve($request)]);
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

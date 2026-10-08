<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Http\Controllers;

use App\Modules\Fiscal\Application\Webhooks\Actions\AtualizarWebhook;
use App\Modules\Fiscal\Application\Webhooks\Actions\CriarWebhook;
use App\Modules\Fiscal\Application\Webhooks\Actions\EnviarTesteDeWebhook;
use App\Modules\Fiscal\Application\Webhooks\Actions\ExcluirWebhook;
use App\Modules\Fiscal\Application\Webhooks\Actions\ListarEntregasDeWebhook;
use App\Modules\Fiscal\Application\Webhooks\Actions\ListarWebhooks;
use App\Modules\Fiscal\Application\Webhooks\Actions\ObterEntregaDeWebhook;
use App\Modules\Fiscal\Application\Webhooks\Actions\ObterWebhook;
use App\Modules\Fiscal\Application\Webhooks\Actions\ReenviarEntrega;
use App\Modules\Fiscal\Domain\Models\WebhookEntrega;
use App\Modules\Fiscal\Http\Resources\WebhookEntregaResource;
use App\Modules\Fiscal\Http\Resources\WebhookResource;
use App\Modules\Identity\Domain\Models\Usuario;
use App\Modules\Shared\Http\PaginadorPorCursor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/** Serve `/api/app/webhooks` e `/api/v1/webhooks`: as duas superfícies chamam as mesmas Actions. */
final class WebhooksController
{
    private const CAMPOS = ['url', 'descricao', 'eventos', 'ambiente', 'ativo'];

    public function index(Request $request, ListarWebhooks $listar): AnonymousResourceCollection
    {
        return WebhookResource::collection($listar->executar($this->autor($request)));
    }

    public function store(Request $request, CriarWebhook $criar): JsonResponse
    {
        $resultado = $criar->executar($this->autor($request), $request->only(self::CAMPOS));

        return (new WebhookResource($resultado->modelo))->comSegredo($resultado->segredo)->response()->setStatusCode(201);
    }

    public function show(Request $request, string $id, ObterWebhook $obter): WebhookResource
    {
        return new WebhookResource($obter->executar($this->autor($request), $id));
    }

    public function update(Request $request, string $id, AtualizarWebhook $atualizar): WebhookResource
    {
        return new WebhookResource($atualizar->executar($this->autor($request), $id, $request->only(self::CAMPOS)));
    }

    public function destroy(Request $request, string $id, ExcluirWebhook $excluir): Response
    {
        $excluir->executar($this->autor($request), $id);

        return response()->noContent();
    }

    public function entregas(Request $request, string $id, ListarEntregasDeWebhook $listar, PaginadorPorCursor $paginador): JsonResponse
    {
        return response()->json($listar->executar(
            $this->autor($request), $id, $request, $paginador,
            fn (WebhookEntrega $entrega): array => (new WebhookEntregaResource($entrega))->resolve($request),
        ));
    }

    public function entrega(Request $request, string $id, ObterEntregaDeWebhook $obter): WebhookEntregaResource
    {
        return (new WebhookEntregaResource($obter->executar($this->autor($request), $id)))->comPayload();
    }

    public function testar(Request $request, string $id, EnviarTesteDeWebhook $testar): JsonResponse
    {
        $entrega = $testar->executar($this->autor($request), $id)->load('tentativasLog');

        return (new WebhookEntregaResource($entrega))->comPayload()->response()->setStatusCode(202);
    }

    public function reenviar(Request $request, string $id, ReenviarEntrega $reenviar): JsonResponse
    {
        $entrega = $reenviar->executar($this->autor($request), $id)->refresh()->load('tentativasLog');

        return (new WebhookEntregaResource($entrega))->comPayload()->response()->setStatusCode(202);
    }

    private function autor(Request $request): Usuario
    {
        /** @var Usuario $autor */
        $autor = $request->user();

        return $autor;
    }
}

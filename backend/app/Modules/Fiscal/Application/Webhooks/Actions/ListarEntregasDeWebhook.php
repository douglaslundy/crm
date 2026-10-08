<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Application\Webhooks\Actions;

use App\Modules\Fiscal\Application\Webhooks\PoliticaDeWebhooks;
use App\Modules\Fiscal\Domain\Enums\EstadoDaEntrega;
use App\Modules\Fiscal\Domain\Models\WebhookEndpoint;
use App\Modules\Fiscal\Domain\Models\WebhookEntrega;
use App\Modules\Identity\Domain\Models\Usuario;
use App\Modules\Shared\Http\PaginadorPorCursor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

final class ListarEntregasDeWebhook
{
    public function __construct(private readonly PoliticaDeWebhooks $politica) {}

    /**
     * @param  callable(WebhookEntrega): array<string, mixed>  $mapa
     * @return array{data: list<array<string, mixed>>, links: array{proxima: ?string}, meta: array{limite: int}}
     */
    public function executar(Usuario $autor, string $webhookId, Request $request, PaginadorPorCursor $paginador, callable $mapa): array
    {
        $this->politica->garantirPodeGerenciar($autor);

        $endpoint = WebhookEndpoint::query()->findOrFail($webhookId);
        $filtros = Validator::make($request->query(), [
            'estado' => ['sometimes', 'string', Rule::enum(EstadoDaEntrega::class)],
        ], ['estado.enum' => 'Use PENDENTE, ENTREGUE ou FALHOU.'])->validate();

        $query = WebhookEntrega::query()->where('webhook_endpoint_id', $endpoint->id)->with('tentativasLog');
        if (isset($filtros['estado'])) {
            $query->where('estado', $filtros['estado']);
        }

        return $paginador->paginar($query, $request, $mapa);
    }
}

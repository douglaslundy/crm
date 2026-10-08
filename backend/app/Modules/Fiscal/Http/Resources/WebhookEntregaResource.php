<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Http\Resources;

use App\Modules\Fiscal\Domain\Models\WebhookEntrega;
use App\Modules\Fiscal\Domain\Models\WebhookTentativa;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A lista não traz o corpo enviado; o `GET` de uma entrega o traz (`comPayload`).
 *
 * @mixin WebhookEntrega
 */
final class WebhookEntregaResource extends JsonResource
{
    private bool $comPayload = false;

    /** Não é argumento do construtor: a coleção do Laravel passa o índice como segundo argumento. */
    public function comPayload(): self
    {
        $this->comPayload = true;

        return $this;
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $dados = [
            'id' => $this->id,
            'webhook_id' => $this->webhook_endpoint_id,
            'nota_id' => $this->nota_id,
            'evento' => $this->evento,
            'evento_id' => $this->evento_id,
            'estado' => $this->estado->value,
            'tentativas' => $this->tentativas,
            'proxima_tentativa_em' => $this->proxima_tentativa_em?->toIso8601String(),
            'entregue_em' => $this->entregue_em?->toIso8601String(),
            'criada_em' => $this->created_at->toIso8601String(),
            'tentativas_log' => $this->whenLoaded('tentativasLog', fn (): array => $this->tentativasLog->map(fn (WebhookTentativa $t): array => [
                'numero' => $t->numero, 'status_http' => $t->status_http, 'duracao_ms' => $t->duracao_ms,
                'erro' => $t->erro, 'resposta' => $t->resposta, 'em' => $t->created_at->toIso8601String(),
            ])->all()),
        ];

        if ($this->comPayload) {
            $dados['payload'] = $this->payload;
        }

        return $dados;
    }
}

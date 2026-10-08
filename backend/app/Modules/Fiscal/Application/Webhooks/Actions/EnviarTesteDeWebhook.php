<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Application\Webhooks\Actions;

use App\Modules\Fiscal\Application\Webhooks\DespachanteDeEntrega;
use App\Modules\Fiscal\Application\Webhooks\MontadorDoPayload;
use App\Modules\Fiscal\Application\Webhooks\PoliticaDeWebhooks;
use App\Modules\Fiscal\Domain\Enums\EstadoDaEntrega;
use App\Modules\Fiscal\Domain\Enums\EventoDeWebhook;
use App\Modules\Fiscal\Domain\Exceptions\WebhookInativoException;
use App\Modules\Fiscal\Domain\Models\WebhookEndpoint;
use App\Modules\Fiscal\Domain\Models\WebhookEntrega;
use App\Modules\Identity\Domain\Models\Usuario;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class EnviarTesteDeWebhook
{
    public function __construct(
        private readonly PoliticaDeWebhooks $politica,
        private readonly MontadorDoPayload $montador,
        private readonly DespachanteDeEntrega $despachante,
    ) {}

    /** Cria e envia um evento `webhook.teste`. Com a fila `sync` o resultado já vem preenchido; senão, consulte as entregas. */
    public function executar(Usuario $autor, string $webhookId): WebhookEntrega
    {
        $this->politica->garantirPodeGerenciar($autor);

        $entrega = DB::transaction(function () use ($autor, $webhookId): WebhookEntrega {
            $endpoint = WebhookEndpoint::query()->findOrFail($webhookId);
            if (! $endpoint->ativo) {
                throw new WebhookInativoException;
            }

            $eventoId = (string) Str::uuid7();
            $entrega = new WebhookEntrega;
            $entrega->forceFill([
                'webhook_endpoint_id' => $endpoint->id, 'nota_evento_id' => null, 'nota_id' => null,
                'evento' => EventoDeWebhook::Teste->value, 'evento_id' => $eventoId,
                'payload' => $this->montador->paraTeste($eventoId, $endpoint->ambiente->value),
                'estado' => EstadoDaEntrega::Pendente, 'tentativas' => 0, 'tentativas_na_rodada' => 0, 'proxima_tentativa_em' => now(),
            ])->save();

            activity('webhooks')->performedOn($endpoint)->causedBy($autor)->event('webhook_testado')
                ->withProperties(['entrega_id' => $entrega->id])
                ->log('Evento de teste enviado');

            DB::afterCommit(fn () => $this->despachante->despachar($entrega->id, $entrega->tenant_id));

            return $entrega;
        });

        return $entrega->refresh();
    }
}

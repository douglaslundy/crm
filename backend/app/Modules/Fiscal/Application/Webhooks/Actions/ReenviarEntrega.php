<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Application\Webhooks\Actions;

use App\Modules\Fiscal\Application\Webhooks\DespachanteDeEntrega;
use App\Modules\Fiscal\Application\Webhooks\PoliticaDeWebhooks;
use App\Modules\Fiscal\Domain\Enums\EstadoDaEntrega;
use App\Modules\Fiscal\Domain\Exceptions\WebhookInativoException;
use App\Modules\Fiscal\Domain\Models\WebhookEndpoint;
use App\Modules\Fiscal\Domain\Models\WebhookEntrega;
use App\Modules\Identity\Domain\Models\Usuario;
use Illuminate\Support\Facades\DB;

final class ReenviarEntrega
{
    public function __construct(
        private readonly PoliticaDeWebhooks $politica,
        private readonly DespachanteDeEntrega $despachante,
    ) {}

    /**
     * Reenvia uma entrega já concluída (ENTREGUE ou FALHOU) com o mesmo evento_id e o mesmo corpo, numa nova rodada
     * de até 7 tentativas. Uma entrega ainda PENDENTE já está na fila: nada muda, para não enviar duas vezes.
     */
    public function executar(Usuario $autor, string $entregaId): WebhookEntrega
    {
        $this->politica->garantirPodeGerenciar($autor);

        return DB::transaction(function () use ($autor, $entregaId): WebhookEntrega {
            $entrega = WebhookEntrega::query()->lockForUpdate()->findOrFail($entregaId);
            $endpoint = WebhookEndpoint::query()->findOrFail($entrega->webhook_endpoint_id);
            if (! $endpoint->ativo) {
                throw new WebhookInativoException;
            }
            if ($entrega->estado === EstadoDaEntrega::Pendente) {
                return $entrega;
            }

            $entrega->forceFill([
                'estado' => EstadoDaEntrega::Pendente, 'proxima_tentativa_em' => now(), 'entregue_em' => null,
                'tentativas_na_rodada' => 0,
            ])->save();

            activity('webhooks')->performedOn($endpoint)->causedBy($autor)->event('webhook_reenviado')
                ->withProperties(['entrega_id' => $entrega->id, 'evento' => $entrega->evento, 'evento_id' => $entrega->evento_id])
                ->log('Entrega de webhook reenviada');

            DB::afterCommit(fn () => $this->despachante->despachar($entrega->id, $entrega->tenant_id));

            return $entrega;
        });
    }
}

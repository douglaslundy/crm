<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Application\Webhooks;

use App\Modules\Fiscal\Domain\Enums\EstadoDaEntrega;
use App\Modules\Fiscal\Domain\Enums\EventoDeWebhook;
use App\Modules\Fiscal\Domain\Enums\StatusNota;
use App\Modules\Fiscal\Domain\Models\Nota;
use App\Modules\Fiscal\Domain\Models\NotaEvento;
use App\Modules\Fiscal\Domain\Models\WebhookEndpoint;
use App\Modules\Platform\Application\EntitlementService;
use App\Modules\Platform\Domain\Enums\Modulo;
use App\Modules\Tenancy\Domain\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Transforma uma mudança de status de nota em entregas, uma por endpoint elegível. Roda dentro da transação da mudança
 * (a entrega nasce junto com o status) e só despacha o envio depois do commit.
 */
final class RegistrarEntregasDeWebhook
{
    public function __construct(
        private readonly MontadorDoPayload $montador,
        private readonly DespachanteDeEntrega $despachante,
        private readonly EntitlementService $entitlement,
    ) {}

    /** @return int entregas criadas */
    public function executar(NotaEvento $evento): int
    {
        // Sem mudança de status (por exemplo, falha técnica registrada no histórico) não há o que avisar.
        if ($evento->status_de === $evento->status_para) {
            return 0;
        }
        $tipo = EventoDeWebhook::daNota(StatusNota::from($evento->status_para));
        if ($tipo === null) {
            return 0;
        }

        $nota = Nota::query()->find($evento->nota_id);
        if ($nota === null || $nota->ambiente === null) {
            return 0;
        }

        $tenant = Tenant::query()->find($evento->tenant_id);
        if ($tenant === null || ! $this->entitlement->temModulo($tenant, Modulo::Api)) {
            return 0;
        }

        $criadas = 0;
        $endpoints = WebhookEndpoint::query()->where('ativo', true)->where('ambiente', $nota->ambiente)->get();
        foreach ($endpoints as $endpoint) {
            if (! $endpoint->assina($tipo)) {
                continue;
            }

            $id = (string) Str::uuid7();
            $eventoId = (string) Str::uuid7();
            $payload = $this->montador->paraNota($nota, $tipo, $eventoId, $evento->created_at);

            // Único (endpoint, nota_evento_id): processar o mesmo evento de novo não duplica.
            $inseriu = DB::table('webhook_entregas')->insertOrIgnore([
                'id' => $id, 'tenant_id' => $evento->tenant_id, 'webhook_endpoint_id' => $endpoint->id,
                'nota_evento_id' => $evento->id, 'nota_id' => $nota->id,
                'evento' => $tipo->value, 'evento_id' => $eventoId,
                'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'estado' => EstadoDaEntrega::Pendente->value, 'tentativas' => 0, 'tentativas_na_rodada' => 0,
                'proxima_tentativa_em' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]) === 1;
            if (! $inseriu) {
                continue;
            }

            $criadas++;
            $tenantId = $evento->tenant_id;
            DB::afterCommit(fn () => $this->despachante->despachar($id, $tenantId));
        }

        return $criadas;
    }
}

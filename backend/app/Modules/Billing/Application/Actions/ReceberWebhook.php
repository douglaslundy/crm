<?php

declare(strict_types=1);

namespace App\Modules\Billing\Application\Actions;

use App\Modules\Billing\Domain\Exceptions\BillingException;
use App\Modules\Billing\Domain\Models\WebhookRecebido;

/**
 * Registra o evento (idempotência pelo id do evento do gateway) e manda conciliar. A assinatura já foi conferida
 * pelo chamador. Falha transitória relança e deixa o evento sem `processado_em`, para o reenvio do gateway refazer.
 */
final class ReceberWebhook
{
    public function __construct(private readonly ConciliarPagamento $conciliar) {}

    /** @param array<string, mixed> $corpo */
    public function executar(array $corpo, string $dataId): void
    {
        if (($corpo['type'] ?? null) !== 'payment') {
            return;
        }

        $eventoId = (string) ($corpo['id'] ?? sha1((string) json_encode($corpo)));
        $evento = WebhookRecebido::query()->firstOrCreate(
            ['gateway_evento_id' => $eventoId],
            ['tipo' => 'payment', 'recebido_em' => now()],
        );
        if ($evento->processado_em !== null) {
            return;   // duplicado
        }

        try {
            $this->conciliar->executar($dataId);
        } catch (BillingException $e) {
            // Erro de regra (ex.: valor divergente) não melhora com reenvio: registra e encerra o evento.
            report($e);
        }
        $evento->forceFill(['processado_em' => now()])->save();
    }
}

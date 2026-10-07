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
    /** Códigos que encerram o evento: reenviar o mesmo webhook não muda o resultado. */
    private const ERROS_DE_REGRA = ['BILLING_VALOR_DIVERGENTE'];

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
            // Só erro de regra (valor divergente) não melhora com reenvio: registra e encerra o evento. Falha de
            // comunicação com o gateway (ou instalação sem token) é transitória: relança, o evento fica aberto e o
            // Mercado Pago reenvia.
            if (! in_array($e->codigo(), self::ERROS_DE_REGRA, true)) {
                throw $e;
            }
            report($e);
        }
        $evento->forceFill(['processado_em' => now()])->save();
    }
}

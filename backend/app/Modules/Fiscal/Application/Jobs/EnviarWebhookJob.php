<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Application\Jobs;

use App\Modules\Fiscal\Application\Webhooks\EntregadorDeWebhook;
use App\Modules\Tenancy\Domain\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

/** Uma tentativa de envio. A retentativa é da tabela (comando `webhooks:reenviar-pendentes`), por isso `tries = 1`. */
final class EnviarWebhookJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 1;

    public int $timeout = 30;

    public function __construct(
        public readonly string $entregaId,
        public readonly string $tenantId,
        public readonly bool $reservada = false,
    ) {}

    public function handle(EntregadorDeWebhook $entregador, TenantContext $contexto): void
    {
        // Num worker o contexto anterior é nulo; com fila `sync` restauramos o do chamador.
        $anterior = $contexto->id();
        $contexto->set($this->tenantId);
        try {
            $entregador->enviar($this->entregaId, $this->reservada);
        } catch (Throwable $e) {
            // A reserva expira sozinha e o comando retoma a entrega.
            report($e);
        } finally {
            $contexto->set($anterior);
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Application\Webhooks;

use App\Modules\Fiscal\Application\Jobs\EnviarWebhookJob;

final class DespachanteDeEntregaPorJob implements DespachanteDeEntrega
{
    public function despachar(string $entregaId, string $tenantId, bool $reservada = false): void
    {
        EnviarWebhookJob::dispatch($entregaId, $tenantId, $reservada);
    }
}

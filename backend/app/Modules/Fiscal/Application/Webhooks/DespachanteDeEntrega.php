<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Application\Webhooks;

/** Manda uma entrega para envio. Existe como contrato para o gatilho e o reenvio não conhecerem a fila. */
interface DespachanteDeEntrega
{
    public function despachar(string $entregaId, string $tenantId): void;
}

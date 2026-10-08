<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Application\Webhooks\Actions;

use App\Modules\Fiscal\Application\Webhooks\PoliticaDeWebhooks;
use App\Modules\Fiscal\Domain\Models\WebhookEntrega;
use App\Modules\Identity\Domain\Models\Usuario;

final class ObterEntregaDeWebhook
{
    public function __construct(private readonly PoliticaDeWebhooks $politica) {}

    /** Traz o log de tentativas; o payload vem no próprio modelo. */
    public function executar(Usuario $autor, string $entregaId): WebhookEntrega
    {
        $this->politica->garantirPodeGerenciar($autor);

        return WebhookEntrega::query()->with('tentativasLog')->findOrFail($entregaId);
    }
}

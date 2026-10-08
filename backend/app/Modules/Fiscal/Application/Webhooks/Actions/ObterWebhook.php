<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Application\Webhooks\Actions;

use App\Modules\Fiscal\Application\Webhooks\PoliticaDeWebhooks;
use App\Modules\Fiscal\Domain\Models\WebhookEndpoint;
use App\Modules\Identity\Domain\Models\Usuario;

final class ObterWebhook
{
    public function __construct(private readonly PoliticaDeWebhooks $politica) {}

    public function executar(Usuario $autor, string $id): WebhookEndpoint
    {
        $this->politica->garantirPodeGerenciar($autor);

        return WebhookEndpoint::query()->findOrFail($id);
    }
}

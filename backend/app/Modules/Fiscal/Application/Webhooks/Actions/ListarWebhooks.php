<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Application\Webhooks\Actions;

use App\Modules\Fiscal\Application\Webhooks\PoliticaDeWebhooks;
use App\Modules\Fiscal\Domain\Models\WebhookEndpoint;
use App\Modules\Identity\Domain\Models\Usuario;
use Illuminate\Database\Eloquent\Collection;

final class ListarWebhooks
{
    public function __construct(private readonly PoliticaDeWebhooks $politica) {}

    /** @return Collection<int, WebhookEndpoint> */
    public function executar(Usuario $autor): Collection
    {
        $this->politica->garantirPodeGerenciar($autor);

        return WebhookEndpoint::query()->orderByDesc('created_at')->orderByDesc('id')->get();
    }
}

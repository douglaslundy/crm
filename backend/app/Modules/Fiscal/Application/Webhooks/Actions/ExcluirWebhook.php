<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Application\Webhooks\Actions;

use App\Modules\Fiscal\Application\Webhooks\PoliticaDeWebhooks;
use App\Modules\Fiscal\Domain\Models\WebhookEndpoint;
use App\Modules\Identity\Domain\Models\Usuario;
use Illuminate\Support\Facades\DB;

final class ExcluirWebhook
{
    public function __construct(private readonly PoliticaDeWebhooks $politica) {}

    public function executar(Usuario $autor, string $id): void
    {
        $this->politica->garantirPodeGerenciar($autor);

        DB::transaction(function () use ($autor, $id): void {
            $endpoint = WebhookEndpoint::query()->lockForUpdate()->findOrFail($id);
            $endpoint->delete();

            activity('webhooks')->performedOn($endpoint)->causedBy($autor)->event('webhook_excluido')
                ->withProperties(['url' => $endpoint->url])
                ->log('Webhook excluído');
        });
    }
}

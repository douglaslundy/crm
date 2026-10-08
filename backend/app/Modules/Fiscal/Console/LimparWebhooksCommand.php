<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Console;

use App\Modules\Fiscal\Domain\Enums\EstadoDaEntrega;
use App\Modules\Fiscal\Domain\Models\WebhookEntrega;
use Illuminate\Console\Command;

final class LimparWebhooksCommand extends Command
{
    private const RETENCAO_EM_DIAS = 30;

    protected $signature = 'webhooks:limpar';

    protected $description = 'Apaga o log de entregas de webhook concluídas há mais de 30 dias (as pendentes ficam).';

    public function handle(): int
    {
        // As tentativas saem junto, pela chave estrangeira com cascata.
        $apagadas = WebhookEntrega::withoutTenantScope()
            ->whereIn('estado', [EstadoDaEntrega::Entregue->value, EstadoDaEntrega::Falhou->value])
            ->where('updated_at', '<', now()->subDays(self::RETENCAO_EM_DIAS))
            ->delete();

        $this->info("Entregas de webhook apagadas: {$apagadas}.");

        return self::SUCCESS;
    }
}

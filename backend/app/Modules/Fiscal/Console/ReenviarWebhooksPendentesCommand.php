<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Console;

use App\Modules\Fiscal\Application\Webhooks\DespachanteDeEntrega;
use App\Modules\Fiscal\Domain\Enums\EstadoDaEntrega;
use App\Modules\Fiscal\Domain\Models\WebhookEntrega;
use Illuminate\Console\Command;

final class ReenviarWebhooksPendentesCommand extends Command
{
    private const LOTE = 500;

    protected $signature = 'webhooks:reenviar-pendentes';

    protected $description = 'Despacha o envio das entregas de webhook pendentes cuja próxima tentativa já venceu (todas as empresas).';

    public function handle(DespachanteDeEntrega $despachante): int
    {
        $entregas = WebhookEntrega::withoutTenantScope()
            ->where('estado', EstadoDaEntrega::Pendente->value)
            ->where('proxima_tentativa_em', '<=', now())
            ->orderBy('proxima_tentativa_em')
            ->limit(self::LOTE)
            ->get(['id', 'tenant_id']);

        // O próprio envio disputa a entrega com um UPDATE condicional: despachar duas vezes não envia duas vezes.
        foreach ($entregas as $entrega) {
            $despachante->despachar($entrega->id, $entrega->tenant_id);
        }

        $this->info("Entregas de webhook despachadas: {$entregas->count()}.");

        return self::SUCCESS;
    }
}

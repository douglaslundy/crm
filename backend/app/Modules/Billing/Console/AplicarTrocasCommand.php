<?php

declare(strict_types=1);

namespace App\Modules\Billing\Console;

use App\Modules\Billing\Application\Actions\AplicarDowngrade;
use App\Modules\Billing\Domain\Enums\StatusDaAssinatura;
use App\Modules\Billing\Domain\Models\Assinatura;
use App\Modules\Tenancy\Domain\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/** Aplica os downgrades agendados que chegaram ao fim do período pago. Idempotente. */
final class AplicarTrocasCommand extends Command
{
    protected $signature = 'billing:aplicar-trocas';

    protected $description = 'Troca o plano de quem agendou um downgrade para o fim do período pago.';

    public function handle(AplicarDowngrade $aplicar, TenantContext $contexto): int
    {
        $hoje = CarbonImmutable::today((string) config('app.fuso_negocio'))->toDateString();
        $trocados = 0;
        $anterior = $contexto->id();

        try {
            Assinatura::withoutTenantScope()
                ->where('situacao', StatusDaAssinatura::Ativa->value)
                ->whereNotNull('plano_proximo_id')
                ->whereDate('plano_proximo_em', '<=', $hoje)
                ->lazyById(100)
                ->each(function (Assinatura $assinatura) use ($aplicar, $contexto, &$trocados): void {
                    $contexto->set($assinatura->tenant_id);
                    if ($aplicar->executar($assinatura->id)) {
                        $trocados++;
                    }
                });
        } finally {
            $contexto->set($anterior);
        }

        $this->info("{$trocados} plano(s) trocado(s).");

        return self::SUCCESS;
    }
}

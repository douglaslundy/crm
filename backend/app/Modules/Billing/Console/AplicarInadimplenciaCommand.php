<?php

declare(strict_types=1);

namespace App\Modules\Billing\Console;

use App\Modules\Billing\Domain\Enums\StatusDaAssinatura;
use App\Modules\Billing\Domain\Enums\StatusDaCobranca;
use App\Modules\Billing\Domain\Models\Assinatura;
use App\Modules\Billing\Domain\Models\Cobranca;
use App\Modules\Platform\Application\Actions\MudarSituacaoDaEmpresa;
use App\Modules\Platform\Domain\Exceptions\TransicaoDeSituacaoInvalidaException;
use App\Modules\Tenancy\Domain\Enums\SituacaoAssinatura;
use App\Modules\Tenancy\Domain\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Cobrança pendente que passou do vencimento vira VENCIDA. Pela cobrança vencida mais antiga de cada tenant:
 * a partir de `carencia_inadimplente_dias` o tenant ATIVO fica INADIMPLENTE; a partir de `carencia_suspensao_dias`
 * (ATIVO ou INADIMPLENTE) fica SUSPENSO. Assinatura cancelada e tenant em teste não são tocados. Idempotente.
 */
final class AplicarInadimplenciaCommand extends Command
{
    protected $signature = 'billing:aplicar-inadimplencia';

    protected $description = 'Marca cobranças vencidas e leva os tenants inadimplentes à inadimplência e à suspensão.';

    public function handle(MudarSituacaoDaEmpresa $mudarSituacao): int
    {
        $hoje = CarbonImmutable::today((string) config('app.fuso_negocio'));

        Cobranca::withoutTenantScope()
            ->where('status', StatusDaCobranca::Pendente->value)
            ->whereDate('vence_em', '<', $hoje->toDateString())
            ->update(['status' => StatusDaCobranca::Vencida->value]);

        $naoCanceladas = Assinatura::withoutTenantScope()->where('situacao', '!=', StatusDaAssinatura::Cancelada->value)->select('id');
        $porTenant = Cobranca::withoutTenantScope()
            ->where('status', StatusDaCobranca::Vencida->value)
            ->whereIn('assinatura_id', $naoCanceladas)
            ->selectRaw('tenant_id, min(vence_em) as mais_antiga')
            ->groupBy('tenant_id')
            ->get();

        $inadimplentes = 0;
        $suspensos = 0;
        foreach ($porTenant as $linha) {
            $tenant = Tenant::query()->find($linha->getAttribute('tenant_id'));
            if ($tenant === null) {
                continue;
            }
            $maisAntiga = CarbonImmutable::parse(substr((string) $linha->getAttribute('mais_antiga'), 0, 10))->startOfDay();
            $dias = (int) $maisAntiga->diffInDays($hoje->startOfDay());

            try {
                if ($dias >= (int) config('billing.carencia_suspensao_dias') && in_array($tenant->situacao, [SituacaoAssinatura::Ativa, SituacaoAssinatura::Inadimplente], true)) {
                    $mudarSituacao->executar($tenant, SituacaoAssinatura::Suspensa, "Cobrança vencida há {$dias} dias.", null);
                    $suspensos++;
                } elseif ($dias >= (int) config('billing.carencia_inadimplente_dias') && $tenant->situacao === SituacaoAssinatura::Ativa) {
                    $mudarSituacao->executar($tenant, SituacaoAssinatura::Inadimplente, "Cobrança vencida há {$dias} dias.", null);
                    $inadimplentes++;
                }
            } catch (TransicaoDeSituacaoInvalidaException $e) {
                report($e);
            }
        }

        $this->info("{$inadimplentes} tenant(s) inadimplente(s), {$suspensos} suspenso(s).");

        return self::SUCCESS;
    }
}

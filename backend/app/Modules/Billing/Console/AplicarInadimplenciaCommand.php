<?php

declare(strict_types=1);

namespace App\Modules\Billing\Console;

use App\Modules\Billing\Domain\Enums\StatusDaAssinatura;
use App\Modules\Billing\Domain\Enums\StatusDaCobranca;
use App\Modules\Billing\Domain\Enums\TipoDeCobranca;
use App\Modules\Billing\Domain\Models\Assinatura;
use App\Modules\Billing\Domain\Models\Cobranca;
use App\Modules\Platform\Application\Actions\MudarSituacaoDaEmpresa;
use App\Modules\Platform\Domain\Exceptions\TransicaoDeSituacaoInvalidaException;
use App\Modules\Tenancy\Domain\Enums\SituacaoAssinatura;
use App\Modules\Tenancy\Domain\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Cobrança pendente que passou do vencimento vira VENCIDA. Os dias de atraso de cada tenant são os da cobrança vencida
 * mais antiga ou, se maiores, os desde o fim do período pago (`paga_ate`) de uma assinatura ativa: a renovação do
 * cartão que o gateway não conseguiu cobrar não gera cobrança nossa, mas o período pago acabou do mesmo jeito.
 * A partir de `carencia_inadimplente_dias` o tenant ATIVO fica INADIMPLENTE; a partir de `carencia_suspensao_dias`
 * (ATIVO ou INADIMPLENTE) fica SUSPENSO. Assinatura cancelada e tenant em teste não são tocados. Idempotente.
 */
final class AplicarInadimplenciaCommand extends Command
{
    protected $signature = 'billing:aplicar-inadimplencia';

    protected $description = 'Marca cobranças vencidas e leva os tenants inadimplentes à inadimplência e à suspensão.';

    public function handle(MudarSituacaoDaEmpresa $mudarSituacao): int
    {
        $hoje = CarbonImmutable::today((string) config('app.fuso_negocio'))->startOfDay();

        // Upgrade não pago não é dívida: a troca simplesmente não acontece e a cobrança é cancelada.
        Cobranca::withoutTenantScope()
            ->where('tipo', TipoDeCobranca::Upgrade->value)
            ->where('status', StatusDaCobranca::Pendente->value)
            ->whereDate('vence_em', '<', $hoje->toDateString())
            ->update(['status' => StatusDaCobranca::Cancelada->value]);

        Cobranca::withoutTenantScope()
            ->where('tipo', '!=', TipoDeCobranca::Upgrade->value)
            ->where('status', StatusDaCobranca::Pendente->value)
            ->whereDate('vence_em', '<', $hoje->toDateString())
            ->update(['status' => StatusDaCobranca::Vencida->value]);

        $inadimplentes = 0;
        $suspensos = 0;
        foreach ($this->diasDeAtrasoPorTenant($hoje) as $tenantId => $dias) {
            $tenant = Tenant::query()->find($tenantId);
            if ($tenant === null) {
                continue;
            }

            try {
                if ($dias >= (int) config('billing.carencia_suspensao_dias') && in_array($tenant->situacao, [SituacaoAssinatura::Ativa, SituacaoAssinatura::Inadimplente], true)) {
                    $mudarSituacao->executar($tenant, SituacaoAssinatura::Suspensa, "Pagamento em atraso há {$dias} dias.", null);
                    $suspensos++;
                } elseif ($dias >= (int) config('billing.carencia_inadimplente_dias') && $tenant->situacao === SituacaoAssinatura::Ativa) {
                    $mudarSituacao->executar($tenant, SituacaoAssinatura::Inadimplente, "Pagamento em atraso há {$dias} dias.", null);
                    $inadimplentes++;
                }
            } catch (TransicaoDeSituacaoInvalidaException $e) {
                report($e);
            }
        }

        $this->info("{$inadimplentes} tenant(s) inadimplente(s), {$suspensos} suspenso(s).");

        return self::SUCCESS;
    }

    /** @return array<string, int> tenant_id => dias de atraso (só os que estão atrasados) */
    private function diasDeAtrasoPorTenant(CarbonImmutable $hoje): array
    {
        $naoCanceladas = Assinatura::withoutTenantScope()->where('situacao', '!=', StatusDaAssinatura::Cancelada->value);
        $dias = [];

        $porCobranca = Cobranca::withoutTenantScope()
            ->where('status', StatusDaCobranca::Vencida->value)
            ->whereIn('assinatura_id', (clone $naoCanceladas)->select('id'))
            ->selectRaw('tenant_id, min(vence_em) as mais_antiga')
            ->groupBy('tenant_id')
            ->get();
        foreach ($porCobranca as $linha) {
            $dias[(string) $linha->getAttribute('tenant_id')] = $this->diasDesde((string) $linha->getAttribute('mais_antiga'), $hoje);
        }

        $periodoAcabou = (clone $naoCanceladas)->where('situacao', StatusDaAssinatura::Ativa->value)
            ->whereNotNull('paga_ate')->whereDate('paga_ate', '<', $hoje->toDateString())
            ->selectRaw('tenant_id, min(paga_ate) as mais_antiga')->groupBy('tenant_id')->get();
        foreach ($periodoAcabou as $linha) {
            $tenantId = (string) $linha->getAttribute('tenant_id');
            $dias[$tenantId] = max($dias[$tenantId] ?? 0, $this->diasDesde((string) $linha->getAttribute('mais_antiga'), $hoje));
        }

        return $dias;
    }

    private function diasDesde(string $data, CarbonImmutable $hoje): int
    {
        return (int) CarbonImmutable::parse(substr($data, 0, 10))->startOfDay()->diffInDays($hoje);
    }
}

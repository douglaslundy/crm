<?php

declare(strict_types=1);

namespace App\Modules\Billing\Application\Actions;

use App\Modules\Billing\Domain\Contracts\GatewayDeCobranca;
use App\Modules\Billing\Domain\Enums\StatusDaAssinatura;
use App\Modules\Billing\Domain\Enums\StatusDaCobranca;
use App\Modules\Billing\Domain\Enums\TipoDeCobranca;
use App\Modules\Billing\Domain\Exceptions\BillingException;
use App\Modules\Billing\Domain\Gateway\ResultadoDePagamento;
use App\Modules\Billing\Domain\Gateway\StatusDoPagamento;
use App\Modules\Billing\Domain\Models\Assinatura;
use App\Modules\Billing\Domain\Models\Cobranca;
use App\Modules\Billing\Domain\PeriodoPago;
use App\Modules\Platform\Application\Actions\MudarSituacaoDaEmpresa;
use App\Modules\Tenancy\Domain\Enums\SituacaoAssinatura;
use App\Modules\Tenancy\Domain\Models\Tenant;
use App\Modules\Tenancy\Domain\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Confere um pagamento no gateway e, se aprovado, dá a cobrança por paga, estende o período e ativa o tenant.
 * Idempotente: o mesmo pagamento conciliado duas vezes não estende o período duas vezes. Roda sem usuário nem
 * contexto de tenant (webhook e comandos): acha tudo sem o escopo e define o contexto do dono para escrever.
 */
final class ConciliarPagamento
{
    public function __construct(
        private readonly GatewayDeCobranca $gateway,
        private readonly MudarSituacaoDaEmpresa $mudarSituacao,
        private readonly TenantContext $contexto,
    ) {}

    /** Devolve a cobrança afetada, ou null quando o pagamento não é de nenhuma cobrança nossa. */
    public function executar(string $gatewayPagamentoId): ?Cobranca
    {
        $pagamento = $this->gateway->buscarPagamento($gatewayPagamentoId);
        $achado = $this->localizar($pagamento);
        if ($achado === null) {
            return null;   // não é de nenhuma cobrança nossa
        }
        ['cobranca' => $cobranca, 'assinatura' => $assinatura, 'tenantId' => $tenantId] = $achado;
        if ($pagamento->status !== StatusDoPagamento::Aprovado) {
            return $cobranca;   // pendente, recusado ou vencido: o `AplicarInadimplencia` cuida do vencimento
        }

        return $this->comTenant($tenantId, fn (): Cobranca => DB::transaction(fn (): Cobranca => $this->aplicar($pagamento, $cobranca, $assinatura)));
    }

    private function aplicar(ResultadoDePagamento $pagamento, ?Cobranca $cobranca, ?Assinatura $assinatura): Cobranca
    {
        if ($cobranca !== null) {
            $cobranca = Cobranca::query()->whereKey($cobranca->id)->lockForUpdate()->firstOrFail();
            if ($cobranca->status === StatusDaCobranca::Paga) {
                return $cobranca;   // já conciliada
            }
            $assinatura = Assinatura::query()->whereKey($cobranca->assinatura_id)->lockForUpdate()->firstOrFail();
        } else {
            // Ciclo seguinte da assinatura de cartão: o gateway cobrou sem uma cobrança nossa pendente.
            $assinatura = Assinatura::query()->whereKey($assinatura?->id)->lockForUpdate()->firstOrFail();
            $cobranca = new Cobranca;
            $cobranca->forceFill([
                'assinatura_id' => $assinatura->id, 'tipo' => TipoDeCobranca::Assinatura,
                'valor_centavos' => $this->valorDaRenovacao($assinatura), 'vence_em' => today((string) config('app.fuso_negocio')),
                'status' => StatusDaCobranca::Pendente, 'metodo' => $assinatura->metodo,
            ]);
        }

        if ($pagamento->valorCentavos !== $cobranca->valor_centavos) {
            throw new BillingException('BILLING_VALOR_DIVERGENTE', 'O valor pago não confere com o da cobrança.', 409);
        }

        $baixa = ['status' => StatusDaCobranca::Paga, 'gateway_pagamento_id' => $pagamento->id, 'pago_em' => now()];

        if (! $cobranca->tipo->estendePeriodo()) {
            // Excedente (e, depois, upgrade): só quita a cobrança; não mexe no período pago da assinatura.
            $cobranca->forceFill($baixa)->save();
            $this->reativarSeEmDia($assinatura);

            return $cobranca;
        }

        $hoje = CarbonImmutable::today((string) config('app.fuso_negocio'));
        $pagaAte = $assinatura->paga_ate === null ? null : CarbonImmutable::parse($assinatura->paga_ate->toDateString());
        $periodo = PeriodoPago::proximo($pagaAte, $hoje, $assinatura->ciclo);

        $cobranca->forceFill($baixa + ['periodo_inicio' => $periodo['inicio']->toDateString(), 'periodo_fim' => $periodo['fim']->toDateString()])->save();

        if ($assinatura->situacao !== StatusDaAssinatura::Cancelada) {
            $assinatura->forceFill(['paga_ate' => $periodo['fim']->toDateString(), 'situacao' => StatusDaAssinatura::Ativa])->save();
            // O pagamento da mensalidade quita as mensalidades vencidas (o excedente vencido continua devido): sem isso o
            // tenant seria suspenso de novo na próxima rodada, que conta a partir da cobrança vencida mais antiga.
            Cobranca::query()->where('assinatura_id', $assinatura->id)->where('tipo', TipoDeCobranca::Assinatura->value)
                ->where('status', StatusDaCobranca::Vencida->value)->whereKeyNot($cobranca->id)
                ->update(['status' => StatusDaCobranca::Cancelada->value]);
            $this->ativarTenant($assinatura);
        }

        return $cobranca;
    }

    /**
     * Quem foi levado à inadimplência ou à suspensão por uma cobrança que não estende o período (o excedente) volta a
     * ATIVA ao quitá-la, desde que a assinatura esteja ativa e paga até hoje e não reste nenhuma cobrança vencida.
     */
    private function reativarSeEmDia(Assinatura $assinatura): void
    {
        $hoje = CarbonImmutable::today((string) config('app.fuso_negocio'));
        if ($assinatura->situacao !== StatusDaAssinatura::Ativa || $assinatura->paga_ate === null || $assinatura->paga_ate->toDateString() < $hoje->toDateString()) {
            return;
        }
        if (Cobranca::query()->where('assinatura_id', $assinatura->id)->where('status', StatusDaCobranca::Vencida->value)->exists()) {
            return;
        }
        $tenant = Tenant::query()->lockForUpdate()->findOrFail($assinatura->tenant_id);
        if (in_array($tenant->situacao, [SituacaoAssinatura::Inadimplente, SituacaoAssinatura::Suspensa], true)) {
            $this->mudarSituacao->executar($tenant, SituacaoAssinatura::Ativa, 'Pagamento confirmado.', null);
        }
    }

    /**
     * O cartão renova pelo valor que o cliente aceitou na última cobrança paga; só sem histórico vale o preço do plano.
     * Assim uma mudança de preço do plano não faz toda renovação falhar na conferência do valor.
     */
    private function valorDaRenovacao(Assinatura $assinatura): int
    {
        $anterior = Cobranca::query()->where('assinatura_id', $assinatura->id)->where('status', StatusDaCobranca::Paga->value)->latest('pago_em')->value('valor_centavos');

        return is_int($anterior) ? $anterior : $assinatura->ciclo->precoDo($assinatura->plano);
    }

    private function ativarTenant(Assinatura $assinatura): void
    {
        $tenant = Tenant::query()->lockForUpdate()->findOrFail($assinatura->tenant_id);
        if ($tenant->plano_id !== $assinatura->plano_id) {
            $tenant->forceFill(['plano_id' => $assinatura->plano_id])->save();
        }
        $reativaveis = [SituacaoAssinatura::Pendente, SituacaoAssinatura::Teste, SituacaoAssinatura::Inadimplente, SituacaoAssinatura::Suspensa];
        if (in_array($tenant->situacao, $reativaveis, true)) {
            $this->mudarSituacao->executar($tenant, SituacaoAssinatura::Ativa, 'Pagamento confirmado.', null);
        }
    }

    /**
     * Ordem: pelo id do pagamento (reaplicação), pela referência externa (id da nossa cobrança) e, por último,
     * pela assinatura do gateway (ciclos do cartão): a cobrança em aberto mais antiga dela, se houver.
     *
     * @return ?array{cobranca: ?Cobranca, assinatura: ?Assinatura, tenantId: string}
     */
    private function localizar(ResultadoDePagamento $pagamento): ?array
    {
        $cobranca = Cobranca::withoutTenantScope()->where('gateway_pagamento_id', $pagamento->id)->first();
        if ($cobranca === null && $pagamento->referenciaExterna !== null && Str::isUuid($pagamento->referenciaExterna)) {
            $cobranca = Cobranca::withoutTenantScope()->whereKey($pagamento->referenciaExterna)->first();
            // O gateway pode repetir a referência da primeira cobrança em cada pagamento recorrente: se ela já foi paga
            // por outro pagamento e este é de uma assinatura, é uma renovação (cai na busca pela assinatura).
            if ($cobranca !== null && $cobranca->status === StatusDaCobranca::Paga && $cobranca->gateway_pagamento_id !== $pagamento->id && $pagamento->assinaturaGatewayId !== null) {
                $cobranca = null;
            }
        }
        if ($cobranca !== null) {
            return ['cobranca' => $cobranca, 'assinatura' => null, 'tenantId' => $cobranca->tenant_id];
        }
        if ($pagamento->assinaturaGatewayId === null) {
            return null;
        }

        $assinatura = Assinatura::withoutTenantScope()->where('gateway_assinatura_id', $pagamento->assinaturaGatewayId)->first();
        if ($assinatura === null) {
            return null;
        }
        $aberta = Cobranca::withoutTenantScope()->where('assinatura_id', $assinatura->id)
            ->whereIn('status', [StatusDaCobranca::Pendente->value, StatusDaCobranca::Vencida->value])->orderBy('vence_em')->first();

        return [
            'cobranca' => $aberta, 'assinatura' => $aberta === null ? $assinatura : null,
            'tenantId' => $assinatura->tenant_id,
        ];
    }

    /**
     * @template T
     *
     * @param  callable(): T  $acao
     * @return T
     */
    private function comTenant(string $tenantId, callable $acao): mixed
    {
        $anterior = $this->contexto->id();
        $this->contexto->set($tenantId);
        try {
            return $acao();
        } finally {
            $this->contexto->set($anterior);
        }
    }
}

<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use App\Modules\Billing\Domain\Contracts\GatewayDeCobranca;
use App\Modules\Billing\Domain\Enums\MetodoDePagamento;
use App\Modules\Billing\Domain\Enums\StatusDaAssinatura;
use App\Modules\Billing\Domain\Models\Assinatura;
use App\Modules\Billing\Domain\Models\Cobranca;
use App\Modules\Identity\Domain\Enums\Papel;
use App\Modules\Identity\Domain\Models\Usuario;
use App\Modules\Platform\Domain\Models\Plano;
use App\Modules\Tenancy\Domain\Enums\SituacaoAssinatura;
use App\Modules\Tenancy\Domain\Models\Tenant;
use App\Modules\Tenancy\Domain\TenantContext;

/** Tenant em teste com um plano pago (mensal R$ 100,00, anual R$ 1.000,00), um admin e o gateway falso no container. */
trait CenarioDeCobranca
{
    protected Tenant $tenant;

    protected Usuario $admin;

    protected Plano $plano;

    protected GatewayDeCobrancaFake $gateway;

    protected function montarCenarioCobranca(): void
    {
        $this->plano = Plano::factory()->create(['preco_mensal_centavos' => 10000, 'preco_anual_centavos' => 100000]);
        $this->tenant = Tenant::factory()->for($this->plano)->situacao(SituacaoAssinatura::Teste)->create(['cnpj' => '11444777000161']);
        $this->admin = Usuario::factory()->for($this->tenant)->create(['papel' => Papel::Admin]);
        app(TenantContext::class)->set($this->tenant->id);
        $this->gateway = new GatewayDeCobrancaFake;
        app()->instance(GatewayDeCobranca::class, $this->gateway);
    }

    /**
     * Assinatura PENDENTE (boleto) com uma cobrança PENDENTE de R$ 100,00 vencendo hoje.
     *
     * @param  array<string, mixed>  $campos  sobrescreve campos da cobrança
     */
    protected function cobrancaPendente(array $campos = []): Cobranca
    {
        $assinatura = Assinatura::factory()->create(['plano_id' => $this->plano->id]);
        $assinatura->forceFill(['situacao' => StatusDaAssinatura::Pendente, 'gateway_assinatura_id' => 'pre-1', 'metodo' => MetodoDePagamento::Boleto])->save();
        $cobranca = Cobranca::factory()->for($assinatura)->create();
        $cobranca->forceFill($campos)->save();

        return $cobranca->refresh();
    }
}

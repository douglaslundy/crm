<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Modules\Billing\Domain\Enums\CicloDeCobranca;
use App\Modules\Billing\Domain\Exceptions\BillingException;
use App\Modules\Billing\Domain\Models\Assinatura;
use App\Modules\Billing\Domain\Models\Cobranca;
use App\Modules\Platform\Domain\Models\Plano;
use App\Modules\Tenancy\Domain\Models\Tenant;
use App\Modules\Tenancy\Domain\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BillingIsolamentoTest extends TestCase
{
    use RefreshDatabase;

    public function test_assinatura_e_cobranca_sao_isoladas_por_tenant(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        $contexto = app(TenantContext::class);

        $contexto->set($tenantA->id);
        $assinatura = Assinatura::factory()->create();
        $cobranca = Cobranca::factory()->for($assinatura)->create();
        $this->assertSame($tenantA->id, $assinatura->tenant_id);
        $this->assertSame($tenantA->id, $cobranca->tenant_id);

        $contexto->set($tenantB->id);
        $this->assertSame(0, Assinatura::query()->count());
        $this->assertSame(0, Cobranca::query()->count());
        $this->assertNull(Assinatura::query()->find($assinatura->id));
        $this->assertNull(Cobranca::query()->find($cobranca->id));
    }

    public function test_ciclo_define_meses_e_preco_do_plano(): void
    {
        $plano = Plano::factory()->create(['preco_mensal_centavos' => 10000, 'preco_anual_centavos' => 100000]);

        $this->assertSame([1, 12], [CicloDeCobranca::Mensal->meses(), CicloDeCobranca::Anual->meses()]);
        $this->assertSame([10000, 100000], [CicloDeCobranca::Mensal->precoDo($plano), CicloDeCobranca::Anual->precoDo($plano)]);
    }

    public function test_plano_sem_preco_do_ciclo_recusa_o_ciclo(): void
    {
        $plano = Plano::factory()->create(['preco_mensal_centavos' => 10000, 'preco_anual_centavos' => null]);

        try {
            CicloDeCobranca::Anual->precoDo($plano);
            $this->fail('deveria recusar');
        } catch (BillingException $e) {
            $this->assertSame('BILLING_CICLO_INDISPONIVEL', $e->codigo());
        }
    }
}

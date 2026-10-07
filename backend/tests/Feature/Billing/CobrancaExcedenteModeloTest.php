<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Modules\Billing\Domain\Enums\StatusDaCobranca;
use App\Modules\Billing\Domain\Enums\TipoDeCobranca;
use App\Modules\Billing\Domain\Models\Assinatura;
use App\Modules\Billing\Domain\Models\Cobranca;
use App\Modules\Billing\Http\Resources\CobrancaResource;
use App\Modules\Tenancy\Domain\Models\Tenant;
use App\Modules\Tenancy\Domain\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CobrancaExcedenteModeloTest extends TestCase
{
    use RefreshDatabase;

    private function assinatura(): Assinatura
    {
        $tenant = Tenant::factory()->create();
        app(TenantContext::class)->set($tenant->id);

        return Assinatura::factory()->create();
    }

    private function excedente(Assinatura $a, string $mes = '2026-10-01'): Cobranca
    {
        $c = new Cobranca;
        $c->forceFill([
            'assinatura_id' => $a->id, 'tipo' => TipoDeCobranca::Excedente, 'valor_centavos' => 5000, 'vence_em' => '2026-11-10',
            'status' => StatusDaCobranca::Pendente, 'metodo' => null, 'mes_referencia' => $mes,
            'documentos_excedentes' => 50, 'valor_unitario_centavos' => 100,
        ])->save();

        return $c;
    }

    public function test_excedente_nasce_sem_metodo_e_com_os_campos_do_mes(): void
    {
        $c = $this->excedente($this->assinatura());

        $c->refresh();
        $this->assertNull($c->metodo);
        $this->assertSame(50, $c->documentos_excedentes);
        $this->assertFalse(TipoDeCobranca::Excedente->estendePeriodo());
        $this->assertTrue(TipoDeCobranca::Assinatura->estendePeriodo());
    }

    public function test_o_banco_nao_aceita_dois_excedentes_do_mesmo_mes_no_tenant_mas_aceita_se_um_foi_cancelado(): void
    {
        $a = $this->assinatura();
        $primeiro = $this->excedente($a);

        try {
            $this->excedente($a);
            $this->fail('deveria recusar o segundo excedente do mesmo mês');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }

        $this->excedente($a, '2026-11-01');

        $primeiro->forceFill(['status' => StatusDaCobranca::Cancelada])->save();
        $this->assertNotNull($this->excedente($a)->id);
    }

    public function test_resource_da_cobranca_aceita_metodo_nulo_e_traz_os_campos_novos(): void
    {
        $c = $this->excedente($this->assinatura())->refresh();

        $dados = (new CobrancaResource($c))->resolve(request());

        $this->assertNull($dados['metodo']);
        $this->assertSame('2026-10-01', $dados['mes_referencia']);
        $this->assertSame(50, $dados['documentos_excedentes']);
        $this->assertSame(100, $dados['valor_unitario_centavos']);
        $this->assertNull($dados['pix_qr_code']);
        $this->assertNull($dados['pix_qr_code_base64']);
        $this->assertNull($dados['pix_expira_em']);
    }
}

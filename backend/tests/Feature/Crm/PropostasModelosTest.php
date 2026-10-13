<?php

declare(strict_types=1);

namespace Tests\Feature\Crm;

use App\Modules\Crm\Domain\Enums\StatusDaProposta;
use App\Modules\Crm\Domain\Models\ItemDaProposta;
use App\Modules\Crm\Domain\Models\Negocio;
use App\Modules\Crm\Domain\Models\Proposta;
use App\Modules\Tenancy\Domain\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\ComCrm;
use Tests\TestCase;

class PropostasModelosTest extends TestCase
{
    use ComCrm;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararCrm();
    }

    /** @param array<string, mixed> $extra */
    private function proposta(array $extra = []): Proposta
    {
        $negocio = Negocio::factory()->create(['responsavel_id' => $this->admin->id]);

        return Proposta::create(array_merge([
            'negocio_id' => $negocio->id, 'cliente_id' => $negocio->cliente_id, 'numero' => 1,
            'status' => StatusDaProposta::Rascunho, 'validade_dias' => 15, 'desconto_centavos' => 0,
            'criado_por' => $this->admin->id,
        ], $extra));
    }

    public function test_cria_proposta_com_itens_e_tenant_do_contexto(): void
    {
        $p = $this->proposta();
        $p->itens()->create(['tipo' => 'PRODUTO', 'item_id' => (string) Str::uuid(), 'descricao' => 'X', 'quantidade' => 2, 'valor_unitario_centavos' => 500, 'ordem' => 1]);

        $this->assertSame($this->tenant->id, $p->tenant_id);
        $this->assertCount(1, $p->refresh()->itens);
        $this->assertSame(500, $p->itens->first()->valor_unitario_centavos);
    }

    public function test_outro_tenant_nao_enxerga(): void
    {
        $p = $this->proposta();
        $p->itens()->create(['tipo' => 'PRODUTO', 'item_id' => (string) Str::uuid(), 'descricao' => 'X', 'quantidade' => 1, 'valor_unitario_centavos' => 1, 'ordem' => 1]);
        app(TenantContext::class)->set((string) Str::uuid());

        $this->assertSame(0, Proposta::query()->count());
        $this->assertSame(0, ItemDaProposta::query()->count());
    }

    public function test_enviada_vencida_e_apresentada_como_expirada(): void
    {
        $p = $this->proposta(['status' => StatusDaProposta::Enviada, 'validade_em' => now()->subMinute()]);

        $this->assertTrue($p->expirada());
        $this->assertSame('EXPIRADA', $p->statusApresentado());
    }

    public function test_enviada_no_prazo_nao_expira_e_aceita_vencida_continua_aceita(): void
    {
        $noPrazo = $this->proposta(['numero' => 2, 'status' => StatusDaProposta::Enviada, 'validade_em' => now()->addDay()]);
        $aceita = $this->proposta(['numero' => 3, 'status' => StatusDaProposta::Aceita, 'validade_em' => now()->subDay()]);

        $this->assertFalse($noPrazo->expirada());
        $this->assertSame('ACEITA', $aceita->statusApresentado());
    }

    public function test_token_e_gravado_criptografado(): void
    {
        $p = $this->proposta();
        $p->forceFill(['token' => 'abc', 'token_hash' => hash('sha256', 'abc')])->save();

        $bruto = DB::table('crm_propostas')->where('id', $p->id)->value('token');
        $this->assertNotSame('abc', $bruto);
        $this->assertSame('abc', $p->refresh()->token);
    }
}

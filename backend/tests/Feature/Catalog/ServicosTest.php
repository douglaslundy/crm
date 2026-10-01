<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Modules\Catalog\Domain\Models\Servico;
use App\Modules\Identity\Domain\Enums\Papel;
use App\Modules\Identity\Domain\Models\Usuario;
use App\Modules\Platform\Domain\Enums\Recurso;
use App\Modules\Platform\Domain\Models\Plano;
use App\Modules\Tenancy\Domain\Models\Tenant;
use App\Modules\Tenancy\Domain\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ServicosTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Usuario $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::factory()->for(Plano::factory()->comLimites([Recurso::Servicos->value => 1]))->create();
        $this->admin = Usuario::factory()->for($this->tenant)->create(['papel' => Papel::Admin]);
        app(TenantContext::class)->set($this->tenant->id);
    }

    public function test_cria_servico(): void
    {
        $this->spa()->actingAs($this->admin)->postJson('/api/app/servicos', [
            'nome' => 'Consultoria', 'preco_centavos' => 10000,
        ])->assertCreated()->assertJsonPath('data.nome', 'Consultoria');
    }

    public function test_codigo_lc116_fora_do_formato_e_recusado(): void
    {
        $this->spa()->actingAs($this->admin)->postJson('/api/app/servicos', [
            'nome' => 'Consultoria', 'preco_centavos' => 10000, 'codigo_lc116' => '1401',
        ])->assertStatus(422)->assertJsonValidationErrors('codigo_lc116');
    }

    public function test_servico_completo_nao_fica_pendente(): void
    {
        $resposta = $this->spa()->actingAs($this->admin)->postJson('/api/app/servicos', [
            'nome' => 'Consultoria', 'preco_centavos' => 10000,
            'codigo_lc116' => '14.01', 'c_trib_nac' => '140101', 'aliquota_iss' => '5.00',
        ])->assertCreated();

        $this->assertFalse($resposta->json('data.pendente_fiscal'));
    }

    public function test_respeita_o_limite_do_plano(): void
    {
        Servico::factory()->for($this->tenant)->create();

        $this->spa()->actingAs($this->admin)->postJson('/api/app/servicos', [
            'nome' => 'Consultoria', 'preco_centavos' => 10000,
        ])->assertStatus(422)->assertJsonPath('codigo', 'LIMITE_DO_PLANO');
    }

    public function test_leitura_nao_cria_servico(): void
    {
        $leitura = Usuario::factory()->for($this->tenant)->create(['papel' => Papel::Leitura]);

        $this->spa()->actingAs($leitura)->postJson('/api/app/servicos', [
            'nome' => 'Consultoria', 'preco_centavos' => 10000,
        ])->assertForbidden()->assertJsonPath('codigo', 'ACESSO_NEGADO');
    }

    public function test_servico_de_outro_tenant_responde_404(): void
    {
        $outro = Tenant::factory()->create();
        app(TenantContext::class)->set($outro->id);
        $alheio = Servico::factory()->for($outro)->create();
        app(TenantContext::class)->set($this->tenant->id);

        $this->spa()->actingAs($this->admin)->getJson("/api/app/servicos/{$alheio->id}")->assertNotFound();
    }
}

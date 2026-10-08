<?php

declare(strict_types=1);

namespace Tests\Feature\Catalog;

use App\Modules\Catalog\Domain\Models\Servico;
use App\Modules\Identity\Domain\Enums\AmbienteDaChave;
use App\Modules\Identity\Domain\Enums\Papel;
use App\Modules\Identity\Domain\GeradorDeChaveDeApi;
use App\Modules\Identity\Domain\Models\ChaveDeApi;
use App\Modules\Identity\Domain\Models\Usuario;
use App\Modules\Platform\Domain\Enums\Modulo;
use App\Modules\Platform\Domain\Enums\Recurso;
use App\Modules\Platform\Domain\Models\Plano;
use App\Modules\Tenancy\Domain\Models\Tenant;
use App\Modules\Tenancy\Domain\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ServicoCodigoTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Usuario $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::factory()->for(Plano::factory()->comLimites([Recurso::Servicos->value => 10]))->create();
        $this->admin = Usuario::factory()->for($this->tenant)->create(['papel' => Papel::Admin]);
        app(TenantContext::class)->set($this->tenant->id);
    }

    /** @return array<string, mixed> */
    private function corpo(array $extra = []): array
    {
        return $extra + ['nome' => 'Consultoria', 'preco_centavos' => 10000];
    }

    public function test_cria_servico_com_codigo_e_devolve_o_codigo(): void
    {
        $this->spa()->actingAs($this->admin)->postJson('/api/app/servicos', $this->corpo(['codigo' => 'CONS-01']))
            ->assertCreated()->assertJsonPath('data.codigo', 'CONS-01');
        $this->assertDatabaseHas('servicos', ['codigo' => 'CONS-01']);
    }

    public function test_codigo_repetido_na_mesma_empresa_e_recusado(): void
    {
        Servico::factory()->for($this->tenant)->create(['codigo' => 'CONS-01']);

        $this->spa()->actingAs($this->admin)->postJson('/api/app/servicos', $this->corpo(['codigo' => 'CONS-01']))
            ->assertUnprocessable()->assertJsonValidationErrors('codigo');
    }

    public function test_mesmo_codigo_em_outra_empresa_e_aceito(): void
    {
        $outro = Tenant::factory()->create();
        app(TenantContext::class)->set($outro->id);
        Servico::factory()->for($outro)->create(['codigo' => 'CONS-01']);
        app(TenantContext::class)->set($this->tenant->id);

        $this->spa()->actingAs($this->admin)->postJson('/api/app/servicos', $this->corpo(['codigo' => 'CONS-01']))->assertCreated();
    }

    public function test_servicos_sem_codigo_coexistem(): void
    {
        $this->spa()->actingAs($this->admin)->postJson('/api/app/servicos', $this->corpo())->assertCreated()->assertJsonPath('data.codigo', null);
        $this->spa()->actingAs($this->admin)->postJson('/api/app/servicos', $this->corpo(['nome' => 'Outro']))->assertCreated();
    }

    public function test_atualizar_com_o_proprio_codigo_nao_colide(): void
    {
        $servico = Servico::factory()->for($this->tenant)->create(['codigo' => 'CONS-01']);

        $this->spa()->actingAs($this->admin)->putJson("/api/app/servicos/{$servico->id}", $this->corpo(['codigo' => 'CONS-01', 'nome' => 'Novo']))
            ->assertOk()->assertJsonPath('data.nome', 'Novo');
        $outro = Servico::factory()->for($this->tenant)->create(['codigo' => 'CONS-02']);
        $this->spa()->actingAs($this->admin)->putJson("/api/app/servicos/{$outro->id}", $this->corpo(['codigo' => 'CONS-01']))
            ->assertUnprocessable()->assertJsonValidationErrors('codigo');
    }

    public function test_codigo_com_mais_de_sessenta_caracteres_e_recusado(): void
    {
        $this->spa()->actingAs($this->admin)->postJson('/api/app/servicos', $this->corpo(['codigo' => str_repeat('a', 61)]))
            ->assertUnprocessable()->assertJsonValidationErrors('codigo');
    }

    public function test_codigo_aparece_na_api_v1(): void
    {
        Servico::factory()->for($this->tenant)->create(['codigo' => 'CONS-01']);
        $gerada = (new GeradorDeChaveDeApi)->gerar(AmbienteDaChave::Live);
        $this->tenant->forceFill(['plano_id' => Plano::factory()->comModulos(Modulo::Api)->comLimites([Recurso::ApiRequisicoesMin->value => -1])->create()->id])->save();
        ChaveDeApi::factory()->create([
            'usuario_id' => $this->admin->id, 'hash' => $gerada->hash, 'prefixo' => $gerada->prefixo, 'ultimos4' => $gerada->ultimos4,
        ]);

        $this->withHeaders(['Authorization' => 'Bearer '.$gerada->texto])->getJson('/api/v1/servicos')
            ->assertOk()->assertJsonPath('data.0.codigo', 'CONS-01');
    }
}

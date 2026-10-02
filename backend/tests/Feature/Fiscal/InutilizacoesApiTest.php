<?php

declare(strict_types=1);

namespace Tests\Feature\Fiscal;

use App\Modules\Fiscal\Domain\Contracts\EmissorDeNfe;
use App\Modules\Fiscal\Domain\Models\Inutilizacao;
use App\Modules\Identity\Domain\Enums\Papel;
use App\Modules\Identity\Domain\Models\Usuario;
use App\Modules\Platform\Domain\Models\Plano;
use App\Modules\Tenancy\Domain\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\CenarioDeNota;
use Tests\Fixtures\EmissorFake;
use Tests\TestCase;

class InutilizacoesApiTest extends TestCase
{
    use CenarioDeNota;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->montarCenario();
        $this->app->instance(EmissorDeNfe::class, new EmissorFake);
        $this->emitente->series()->update(['proximo_numero' => 10]);
    }

    /**
     * @param  array<string, mixed>  $sobrescrever
     * @return array<string, mixed>
     */
    private function corpo(array $sobrescrever = []): array
    {
        return array_replace(['serie' => '1', 'numero_inicial' => 3, 'numero_final' => 5, 'justificativa' => 'Numeração perdida por queda do servidor'], $sobrescrever);
    }

    public function test_cria_e_lista_sem_expor_o_xml(): void
    {
        $this->spaComo($this->admin)->postJson('/api/app/inutilizacoes', $this->corpo())->assertCreated()
            ->assertJsonPath('data.status', 'INUTILIZADA')->assertJsonPath('data.numero_inicial', 3)->assertJsonMissingPath('data.xml_resposta');

        $this->spaComo($this->admin)->getJson('/api/app/inutilizacoes')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.numero_final', 5);
    }

    public function test_validacao_do_corpo(): void
    {
        $this->spaComo($this->admin)->postJson('/api/app/inutilizacoes', $this->corpo(['numero_inicial' => 'a']))->assertStatus(422)->assertJsonValidationErrors('numero_inicial');
        $this->spaComo($this->admin)->postJson('/api/app/inutilizacoes', $this->corpo(['justificativa' => 'curta']))->assertStatus(422);
        $this->spaComo($this->admin)->postJson('/api/app/inutilizacoes', $this->corpo(['serie' => '']))->assertStatus(422)->assertJsonValidationErrors('serie');
    }

    public function test_bloqueio_local_vira_422_com_codigo(): void
    {
        $this->spaComo($this->admin)->postJson('/api/app/inutilizacoes', $this->corpo(['numero_inicial' => 8, 'numero_final' => 12]))
            ->assertStatus(422)->assertJsonPath('codigo', 'FISCAL_FAIXA_NAO_ALOCADA');
    }

    public function test_papeis(): void
    {
        $vendedor = Usuario::factory()->for($this->tenant)->create(['papel' => Papel::Vendedor]);
        $leitura = Usuario::factory()->for($this->tenant)->create(['papel' => Papel::Leitura]);

        $this->spaComo($vendedor)->postJson('/api/app/inutilizacoes', $this->corpo())->assertForbidden();
        $this->spaComo($leitura)->getJson('/api/app/inutilizacoes')->assertOk();
        $this->spaComo($leitura)->postJson('/api/app/inutilizacoes', $this->corpo())->assertForbidden();
    }

    public function test_isolamento_entre_tenants(): void
    {
        Inutilizacao::factory()->create(['emitente_id' => $this->emitente->id]);
        $outro = Tenant::factory()->for(Plano::factory())->create();
        $intruso = Usuario::factory()->for($outro)->create(['papel' => Papel::Admin]);

        $this->spaComo($intruso)->getJson('/api/app/inutilizacoes')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_sem_autenticacao_401(): void
    {
        $this->spa()->getJson('/api/app/inutilizacoes')->assertUnauthorized();
    }
}

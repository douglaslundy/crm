<?php

declare(strict_types=1);

namespace Tests\Feature\Fiscal;

use App\Modules\Identity\Domain\Enums\Papel;
use App\Modules\Identity\Domain\Models\Usuario;
use App\Modules\Platform\Domain\Enums\Modulo;
use App\Modules\Platform\Domain\Models\Plano;
use App\Modules\Tenancy\Domain\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\CenarioDeNota;
use Tests\TestCase;

class NotaNfceApiTest extends TestCase
{
    use CenarioDeNota;
    use RefreshDatabase;

    /**
     * @param  array<string, mixed>  $sobrescrever
     * @return array<string, mixed>
     */
    private function corpo(array $sobrescrever = []): array
    {
        return array_replace([
            'modelo' => 'NFCE', 'cliente_id' => null, 'consumidor_final' => null, 'desconto_centavos' => 0, 'informacoes_complementares' => null,
            'itens' => [['produto_id' => $this->produto->id, 'quantidade' => '1', 'valor_unitario_centavos' => null]],
            'pagamentos' => [['tpag' => '01', 'xpag' => null, 'valor_centavos' => 1000]],
        ], $sobrescrever);
    }

    public function test_cria_rascunho_de_nfce_sem_cliente(): void
    {
        $this->montarCenario(Modulo::FiscalNfe, Modulo::FiscalNfce);

        $id = $this->spaComo($this->admin)->postJson('/api/app/notas', $this->corpo())
            ->assertCreated()->assertJsonPath('data.modelo', 'NFCE')->assertJsonPath('data.status', 'RASCUNHO')->json('data.id');

        $this->spaComo($this->admin)->getJson("/api/app/notas/{$id}")->assertOk()
            ->assertJsonPath('data.modelo', 'NFCE')->assertJsonPath('data.cliente', null);
    }

    public function test_cria_rascunho_de_nfce_com_cliente_opcional(): void
    {
        $this->montarCenario(Modulo::FiscalNfe, Modulo::FiscalNfce);

        $this->spaComo($this->admin)->postJson('/api/app/notas', $this->corpo(['cliente_id' => $this->cliente->id]))
            ->assertCreated()->assertJsonPath('data.modelo', 'NFCE');
    }

    public function test_nfe_continua_exigindo_cliente(): void
    {
        $this->montarCenario(Modulo::FiscalNfe, Modulo::FiscalNfce);

        $this->spaComo($this->admin)->postJson('/api/app/notas', $this->corpo(['modelo' => 'NFE']))
            ->assertStatus(422)->assertJsonValidationErrors('cliente_id');
        $this->spaComo($this->admin)->postJson('/api/app/notas', array_diff_key($this->corpo(), ['modelo' => 1]))
            ->assertStatus(422)->assertJsonValidationErrors('cliente_id');
    }

    public function test_sem_modelo_e_nfe_por_padrao(): void
    {
        $this->montarCenario();

        $this->spaComo($this->admin)->postJson('/api/app/notas', array_diff_key($this->corpo(['cliente_id' => $this->cliente->id]), ['modelo' => 1]))
            ->assertCreated()->assertJsonPath('data.modelo', 'NFE');
    }

    public function test_modelo_invalido_da_422(): void
    {
        $this->montarCenario(Modulo::FiscalNfe, Modulo::FiscalNfce);

        $this->spaComo($this->admin)->postJson('/api/app/notas', $this->corpo(['modelo' => 'XYZ']))
            ->assertStatus(422)->assertJsonValidationErrors('modelo');
    }

    public function test_tenant_sem_modulo_nfce_nao_cria_rascunho_de_nfce(): void
    {
        $this->montarCenario();

        $this->spaComo($this->admin)->postJson('/api/app/notas', $this->corpo())->assertStatus(403)->assertJsonPath('codigo', 'MODULO_NAO_CONTRATADO');
    }

    public function test_nfce_de_outro_tenant_da_404(): void
    {
        $this->montarCenario(Modulo::FiscalNfe, Modulo::FiscalNfce);
        $id = $this->spaComo($this->admin)->postJson('/api/app/notas', $this->corpo())->assertCreated()->json('data.id');
        $outro = Tenant::factory()->for(Plano::factory())->create();
        $intruso = Usuario::factory()->for($outro)->create(['papel' => Papel::Admin]);

        $this->spaComo($intruso)->getJson("/api/app/notas/{$id}")->assertNotFound();
        $this->spaComo($intruso)->putJson("/api/app/notas/{$id}", $this->corpo())->assertNotFound();
    }

    public function test_filtra_a_lista_por_modelo(): void
    {
        $this->montarCenario(Modulo::FiscalNfe, Modulo::FiscalNfce);
        $nfce = $this->spaComo($this->admin)->postJson('/api/app/notas', $this->corpo())->assertCreated()->json('data.id');
        $this->spaComo($this->admin)->postJson('/api/app/notas', $this->corpo(['modelo' => 'NFE', 'cliente_id' => $this->cliente->id]))->assertCreated();

        $this->spaComo($this->admin)->getJson('/api/app/notas?modelo=NFCE')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $nfce);
    }
}

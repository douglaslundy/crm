<?php

declare(strict_types=1);

namespace Tests\Feature\Fiscal;

use App\Modules\Identity\Domain\Enums\Papel;
use App\Modules\Identity\Domain\Models\Usuario;
use App\Modules\Platform\Domain\Enums\Modulo;
use App\Modules\Platform\Domain\Models\Plano;
use App\Modules\Tenancy\Domain\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class EmitenteTest extends TestCase
{
    use RefreshDatabase;

    public function test_get_emitente_cria_o_registro_vazio_na_primeira_vez(): void
    {
        $tenant = Tenant::factory()->for(Plano::factory())->create();
        $admin = Usuario::factory()->for($tenant)->create(['papel' => Papel::Admin]);

        $this->spa()->actingAs($admin)->getJson('/api/app/emitente')
            ->assertOk()
            ->assertJsonPath('data.ambiente_fiscal', 'HOMOLOGACAO')
            ->assertJsonPath('data.certificado_status', 'PENDENTE')
            ->assertJsonPath('data.exige_csc', false);

        $this->assertDatabaseCount('emitentes', 1);
    }

    public function test_exige_csc_e_verdadeiro_quando_o_plano_tem_nfce(): void
    {
        $tenant = Tenant::factory()->for(Plano::factory()->comModulos(Modulo::FiscalNfce))->create();
        $admin = Usuario::factory()->for($tenant)->create(['papel' => Papel::Admin]);

        $this->spa()->actingAs($admin)->getJson('/api/app/emitente')->assertJsonPath('data.exige_csc', true);
    }

    public function test_atualiza_dados_da_empresa(): void
    {
        $tenant = Tenant::factory()->for(Plano::factory())->create();
        $admin = Usuario::factory()->for($tenant)->create(['papel' => Papel::Admin]);

        $this->spa()->actingAs($admin)->putJson('/api/app/emitente/empresa', [
            'cep' => '01001-000', 'logradouro' => 'Praça da Sé', 'numero' => '100',
            'bairro' => 'Sé', 'cidade' => 'São Paulo', 'uf' => 'sp', 'codigo_ibge' => '3550308',
        ])->assertOk()->assertJsonPath('data.uf', 'SP')->assertJsonPath('data.cep', '01001000');
    }

    public function test_atualiza_dados_fiscais(): void
    {
        $tenant = Tenant::factory()->for(Plano::factory())->create();
        $admin = Usuario::factory()->for($tenant)->create(['papel' => Papel::Admin]);

        $this->spa()->actingAs($admin)->putJson('/api/app/emitente/fiscal', [
            'regime_tributario' => 'SIMPLES', 'inscricao_estadual' => 'ISENTO', 'cnae' => '4520001',
        ])->assertOk()->assertJsonPath('data.regime_tributario', 'SIMPLES');
    }

    public function test_consulta_cep_para_o_wizard(): void
    {
        $tenant = Tenant::factory()->for(Plano::factory())->create();
        $admin = Usuario::factory()->for($tenant)->create(['papel' => Papel::Admin]);
        Http::fake(['*/ws/01001000/json/' => Http::response([
            'logradouro' => 'Praça da Sé', 'bairro' => 'Sé', 'localidade' => 'São Paulo', 'uf' => 'SP', 'ibge' => '3550308',
        ])]);

        $this->spa()->actingAs($admin)->getJson('/api/app/emitente/cep/01001000')
            ->assertOk()->assertJsonPath('data.cidade', 'São Paulo');
    }

    public function test_vendedor_nao_altera_o_emitente(): void
    {
        $tenant = Tenant::factory()->for(Plano::factory())->create();
        $vendedor = Usuario::factory()->for($tenant)->create(['papel' => Papel::Vendedor]);

        $this->spa()->actingAs($vendedor)->putJson('/api/app/emitente/fiscal', ['regime_tributario' => 'SIMPLES'])
            ->assertForbidden()->assertJsonPath('codigo', 'ACESSO_NEGADO');
    }

    public function test_leitura_le_mas_nao_escreve(): void
    {
        $tenant = Tenant::factory()->for(Plano::factory())->create();
        $leitura = Usuario::factory()->for($tenant)->create(['papel' => Papel::Leitura]);

        $this->spa()->actingAs($leitura)->getJson('/api/app/emitente')->assertOk();
        $this->spa()->actingAs($leitura)->putJson('/api/app/emitente/fiscal', ['regime_tributario' => 'SIMPLES'])
            ->assertForbidden();
    }
}

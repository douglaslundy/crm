<?php

declare(strict_types=1);

namespace Tests\Feature\Fiscal;

use App\Modules\Fiscal\Domain\Enums\StatusNota;
use App\Modules\Fiscal\Domain\Models\Nota;
use App\Modules\Identity\Domain\Enums\Papel;
use App\Modules\Identity\Domain\Models\Usuario;
use App\Modules\Platform\Domain\Enums\Modulo;
use App\Modules\Platform\Domain\Models\Plano;
use App\Modules\Tenancy\Domain\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\CenarioDeNota;
use Tests\TestCase;

class DashboardFiscalApiTest extends TestCase
{
    use CenarioDeNota;
    use RefreshDatabase;

    private function autorizada(int $total): void
    {
        $nota = Nota::factory()->create(['emitente_id' => $this->emitente->id, 'cliente_id' => $this->cliente->id]);
        $nota->forceFill([
            'status' => StatusNota::Autorizada, 'ambiente' => 'HOMOLOGACAO', 'serie' => '1', 'numero' => 1, 'chave' => str_repeat('1', 44),
            'total_centavos' => $total, 'subtotal_centavos' => $total, 'emitido_em' => now(),
        ])->save();
    }

    public function test_devolve_o_resumo_com_a_estrutura_esperada(): void
    {
        $this->montarCenario();
        $this->autorizada(2500);

        $this->spaComo($this->admin)->getJson('/api/app/dashboard/fiscal')->assertOk()
            ->assertJsonStructure(['data' => [
                'ambiente', 'certificado' => ['status', 'validade', 'dias_restantes'],
                'mes' => ['periodo', 'notas', 'valor_emitido_centavos'],
                'faturamento_12_meses' => [['mes', 'valor_centavos', 'quantidade']],
                'rejeicoes' => ['quantidade', 'recentes'],
            ]])
            ->assertJsonCount(12, 'data.faturamento_12_meses')
            ->assertJsonPath('data.ambiente', 'HOMOLOGACAO')
            ->assertJsonPath('data.mes.valor_emitido_centavos', 2500);
    }

    public function test_papel_leitura_pode_ver(): void
    {
        $this->montarCenario();
        $leitura = Usuario::factory()->for($this->tenant)->create(['papel' => Papel::Leitura]);

        $this->spaComo($leitura)->getJson('/api/app/dashboard/fiscal')->assertOk();
    }

    public function test_sem_login_da_401(): void
    {
        $this->montarCenario();

        $this->spa()->getJson('/api/app/dashboard/fiscal')->assertUnauthorized();
    }

    public function test_plano_sem_modulo_fiscal_da_403(): void
    {
        $this->montarCenario(Modulo::Crm);

        $this->spaComo($this->admin)->getJson('/api/app/dashboard/fiscal')->assertForbidden()->assertJsonPath('codigo', 'MODULO_NAO_CONTRATADO');
    }

    public function test_plano_so_com_nfce_pode_ver(): void
    {
        $this->montarCenario(Modulo::FiscalNfce);

        $this->spaComo($this->admin)->getJson('/api/app/dashboard/fiscal')->assertOk();
    }

    public function test_isolamento_entre_tenants(): void
    {
        $this->montarCenario();
        $this->autorizada(9900);
        $outro = Tenant::factory()->for(Plano::factory()->comModulos(Modulo::FiscalNfe))->create();
        $intruso = Usuario::factory()->for($outro)->create(['papel' => Papel::Admin]);

        $this->spaComo($intruso)->getJson('/api/app/dashboard/fiscal')->assertOk()
            ->assertJsonPath('data.mes.valor_emitido_centavos', 0)
            ->assertJsonPath('data.rejeicoes.quantidade', 0);
    }
}

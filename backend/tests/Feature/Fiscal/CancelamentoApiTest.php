<?php

declare(strict_types=1);

namespace Tests\Feature\Fiscal;

use App\Modules\Fiscal\Domain\Contracts\EmissorDeNfe;
use App\Modules\Fiscal\Domain\Emissao\ResultadoDeCancelamento;
use App\Modules\Fiscal\Domain\Enums\StatusNota;
use App\Modules\Fiscal\Domain\Models\Nota;
use App\Modules\Identity\Domain\Enums\Papel;
use App\Modules\Identity\Domain\Models\Usuario;
use App\Modules\Platform\Domain\Models\Plano;
use App\Modules\Tenancy\Domain\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\CenarioDeNota;
use Tests\Fixtures\EmissorFake;
use Tests\TestCase;

class CancelamentoApiTest extends TestCase
{
    use CenarioDeNota;
    use RefreshDatabase;

    private const JUSTIFICATIVA = 'Cancelamento por erro de digitação do valor total';

    private EmissorFake $emissor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->montarCenario();
        $this->emissor = new EmissorFake;
        $this->app->instance(EmissorDeNfe::class, $this->emissor);
    }

    private function autorizada(): string
    {
        $nota = Nota::factory()->create(['emitente_id' => $this->emitente->id, 'cliente_id' => $this->cliente->id]);
        $nota->forceFill([
            'status' => StatusNota::Autorizada, 'ambiente' => 'HOMOLOGACAO', 'serie' => '1', 'numero' => 1,
            'chave' => str_repeat('1', 44), 'protocolo' => '135260000000001', 'xml_autorizado' => '<nfeProc>x</nfeProc>', 'emitido_em' => now(),
        ])->save();

        return $nota->id;
    }

    public function test_cancela_responde_202_e_conclui_com_o_fake(): void
    {
        $id = $this->autorizada();

        $this->spaComo($this->admin)->postJson("/api/app/notas/{$id}/cancelar", ['justificativa' => self::JUSTIFICATIVA])
            ->assertStatus(202)->assertJsonStructure(['data' => ['id', 'status']]);

        $this->spaComo($this->admin)->getJson("/api/app/notas/{$id}")->assertOk()
            ->assertJsonPath('data.status', 'CANCELADA')
            ->assertJsonPath('data.cancelamento_justificativa', self::JUSTIFICATIVA)
            ->assertJsonPath('data.cancelamento_protocolo', '135260000000099')
            ->assertJsonMissingPath('data.xml_cancelamento');
    }

    public function test_recusa_da_sefaz_fica_no_recurso_e_a_nota_segue_autorizada(): void
    {
        $this->emissor->enfileirarCancelamento(ResultadoDeCancelamento::recusado('501', 'Pedido de Cancelamento intempestivo'));
        $id = $this->autorizada();

        $this->spaComo($this->admin)->postJson("/api/app/notas/{$id}/cancelar", ['justificativa' => self::JUSTIFICATIVA])->assertStatus(202);

        $this->spaComo($this->admin)->getJson("/api/app/notas/{$id}/status")->assertOk()
            ->assertJsonPath('data.status', 'AUTORIZADA')
            ->assertJsonPath('data.cancelamento_cstat', '501')
            ->assertJsonPath('data.cancelamento_motivo', 'Pedido de Cancelamento intempestivo');
    }

    public function test_validacoes_da_justificativa_e_do_status(): void
    {
        $id = $this->autorizada();

        $this->spaComo($this->admin)->postJson("/api/app/notas/{$id}/cancelar", [])->assertStatus(422)->assertJsonValidationErrors('justificativa');
        $this->spaComo($this->admin)->postJson("/api/app/notas/{$id}/cancelar", ['justificativa' => 'curta'])->assertStatus(422);
        $this->spaComo($this->admin)->postJson("/api/app/notas/{$id}/cancelar", ['justificativa' => str_repeat('a', 256)])->assertStatus(422);
        $this->spaComo($this->admin)->postJson("/api/app/notas/{$id}/cancelar", ['justificativa' => str_repeat("\u{1F600}", 20)])
            ->assertStatus(422)->assertJsonPath('codigo', 'JUSTIFICATIVA_INVALIDA');

        Nota::query()->findOrFail($id)->forceFill(['status' => StatusNota::Rejeitada])->save();
        $this->spaComo($this->admin)->postJson("/api/app/notas/{$id}/cancelar", ['justificativa' => self::JUSTIFICATIVA])
            ->assertStatus(409)->assertJsonPath('codigo', 'CANCELAMENTO_NAO_PERMITIDO');
    }

    public function test_baixa_o_xml_do_cancelamento_so_de_nota_cancelada(): void
    {
        $id = $this->autorizada();
        $this->spaComo($this->admin)->get("/api/app/notas/{$id}/xml-cancelamento", ['Accept' => 'application/json'])
            ->assertStatus(409)->assertJsonPath('codigo', 'NOTA_SEM_XML_CANCELAMENTO');

        $this->spaComo($this->admin)->postJson("/api/app/notas/{$id}/cancelar", ['justificativa' => self::JUSTIFICATIVA]);
        $resp = $this->spaComo($this->admin)->get("/api/app/notas/{$id}/xml-cancelamento")->assertOk();
        $this->assertSame('<nfeProc>cancelada</nfeProc>', $resp->getContent());
        $this->assertStringContainsString('NFe-1-cancelamento.xml', (string) $resp->headers->get('Content-Disposition'));
    }

    public function test_papeis(): void
    {
        $vendedor = Usuario::factory()->for($this->tenant)->create(['papel' => Papel::Vendedor]);
        $leitura = Usuario::factory()->for($this->tenant)->create(['papel' => Papel::Leitura]);
        $fiscal = Usuario::factory()->for($this->tenant)->create(['papel' => Papel::Fiscal]);
        $id = $this->autorizada();

        $this->spaComo($vendedor)->postJson("/api/app/notas/{$id}/cancelar", ['justificativa' => self::JUSTIFICATIVA])->assertForbidden();
        $this->spaComo($leitura)->postJson("/api/app/notas/{$id}/cancelar", ['justificativa' => self::JUSTIFICATIVA])->assertForbidden();
        $this->spaComo($fiscal)->postJson("/api/app/notas/{$id}/cancelar", ['justificativa' => self::JUSTIFICATIVA])->assertStatus(202);
    }

    public function test_isolamento_entre_tenants(): void
    {
        $id = $this->autorizada();
        $outro = Tenant::factory()->for(Plano::factory())->create();
        $intruso = Usuario::factory()->for($outro)->create(['papel' => Papel::Admin]);

        $this->spaComo($intruso)->postJson("/api/app/notas/{$id}/cancelar", ['justificativa' => self::JUSTIFICATIVA])->assertNotFound();
        $this->spaComo($intruso)->get("/api/app/notas/{$id}/xml-cancelamento", ['Accept' => 'application/json'])->assertNotFound();
    }

    public function test_sem_autenticacao_401(): void
    {
        $id = $this->autorizada();
        $this->spa()->postJson("/api/app/notas/{$id}/cancelar", ['justificativa' => self::JUSTIFICATIVA])->assertUnauthorized();
    }
}

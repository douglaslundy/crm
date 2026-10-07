<?php

declare(strict_types=1);

namespace Tests\Feature\Fiscal;

use App\Modules\Fiscal\Application\ContadorDeDocumentosMes;
use App\Modules\Fiscal\Domain\Enums\StatusNota;
use App\Modules\Platform\Domain\Enums\Modulo;
use App\Modules\Platform\Domain\Models\Plano;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\CenarioDeNfse;
use Tests\TestCase;

class FronteirasDaNfseTest extends TestCase
{
    use CenarioDeNfse;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->montarCenarioNfse();
    }

    public function test_downloads_levam_o_numero_oficial_da_nfse(): void
    {
        $cancelada = $this->notaDeNfsePronta(StatusNota::Cancelada, [
            'chave' => str_repeat('1', 50), 'numero_nfse' => '42', 'xml_autorizado' => '<NFSe>a</NFSe>', 'xml_cancelamento' => '<evento>c</evento>',
        ]);

        $cancelamento = $this->spaComo($this->admin)->get("/api/app/notas/{$cancelada->id}/xml-cancelamento")->assertOk();
        $this->assertStringContainsString('NFSe-42-cancelamento.xml', (string) $cancelamento->headers->get('Content-Disposition'));

        $autorizada = $this->notaDeNfsePronta(StatusNota::Autorizada, ['chave' => str_repeat('2', 50), 'numero_nfse' => '43', 'xml_autorizado' => '<NFSe>a</NFSe>']);
        $r = $this->spaComo($this->admin)->get("/api/app/notas/{$autorizada->id}/xml")->assertOk();
        $this->assertStringContainsString('NFSe-43.xml', (string) $r->headers->get('Content-Disposition'));
    }

    public function test_inutilizacao_nao_aceita_o_modelo_dps(): void
    {
        $this->spaComo($this->admin)->postJson('/api/app/inutilizacoes', [
            'modelo' => 'DPS', 'serie' => '1', 'numero_inicial' => 1, 'numero_final' => 1, 'justificativa' => 'Numeração perdida na queda do sistema',
        ])->assertStatus(422)->assertJsonValidationErrors('modelo');
    }

    public function test_retransmitir_nota_dps_da_409(): void
    {
        $nota = $this->notaDeNfsePronta(StatusNota::Autorizada);

        $this->spaComo($this->admin)->postJson("/api/app/notas/{$nota->id}/retransmitir")->assertStatus(409);
    }

    public function test_dashboard_fiscal_aceita_o_modulo_nfse_e_recusa_quem_nao_tem_nenhum(): void
    {
        $this->spaComo($this->admin)->getJson('/api/app/dashboard/fiscal')->assertOk();

        $this->tenant->forceFill(['plano_id' => Plano::factory()->comModulos(Modulo::Crm)->create()->id])->save();
        $this->tenant->unsetRelation('plano');
        $this->admin->unsetRelation('tenant');
        $this->spaComo($this->admin)->getJson('/api/app/dashboard/fiscal')->assertStatus(403);
    }

    public function test_nota_dps_autorizada_em_producao_conta_na_cota_e_no_resumo(): void
    {
        $this->notaDeNfsePronta(StatusNota::Autorizada, ['ambiente' => 'PRODUCAO', 'emitido_em' => now(), 'chave' => str_repeat('1', 50), 'numero_nfse' => '1']);
        $this->emitente->forceFill(['ambiente_fiscal' => 'PRODUCAO'])->save();

        $this->assertSame(1, (new ContadorDeDocumentosMes)->contar($this->tenant));
        $r = $this->spaComo($this->admin)->getJson('/api/app/dashboard/fiscal')->assertOk();
        $this->assertSame(15000, $r->json('data.mes.valor_emitido_centavos'));
    }
}

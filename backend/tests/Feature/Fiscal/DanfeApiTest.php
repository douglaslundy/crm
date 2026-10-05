<?php

declare(strict_types=1);

namespace Tests\Feature\Fiscal;

use App\Modules\Fiscal\Domain\Enums\ModeloDocumento;
use App\Modules\Fiscal\Domain\Enums\StatusNota;
use App\Modules\Fiscal\Domain\Models\Nota;
use App\Modules\Identity\Domain\Enums\Papel;
use App\Modules\Identity\Domain\Models\Usuario;
use App\Modules\Platform\Domain\Models\Plano;
use App\Modules\Tenancy\Domain\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\AssinaNfeDeTeste;
use Tests\Fixtures\CenarioDeNota;
use Tests\Fixtures\XmlAutorizadoDeTeste;
use Tests\TestCase;

class DanfeApiTest extends TestCase
{
    use AssinaNfeDeTeste;
    use CenarioDeNota;
    use RefreshDatabase;
    use XmlAutorizadoDeTeste;

    protected function setUp(): void
    {
        parent::setUp();
        $this->montarCenario();
    }

    private function nota(StatusNota $status, bool $comXml = true, bool $cancelada = false, ModeloDocumento $modelo = ModeloDocumento::Nfe): string
    {
        ['xml' => $proc, 'chave' => $chave] = $this->nfeProcDeTeste('2', $modelo);
        $nota = Nota::factory()->create(['emitente_id' => $this->emitente->id, 'cliente_id' => $this->cliente->id]);
        $nota->forceFill([
            'modelo' => $modelo, 'status' => $status, 'ambiente' => 'HOMOLOGACAO', 'serie' => '1', 'numero' => $status === StatusNota::Rascunho ? null : 1, 'chave' => $status === StatusNota::Rascunho ? null : $chave,
            'protocolo' => '135260000000001', 'xml_autorizado' => $comXml ? $proc : null,
            'xml_cancelamento' => $cancelada ? $this->nfeProcCancelado($proc, $chave) : null,
        ])->save();

        return $nota->id;
    }

    public function test_abre_o_pdf_inline_com_o_nome_do_arquivo(): void
    {
        $id = $this->nota(StatusNota::Autorizada);

        $resp = $this->spaComo($this->admin)->get("/api/app/notas/{$id}/pdf")->assertOk();

        $this->assertStringContainsString('application/pdf', (string) $resp->headers->get('Content-Type'));
        $this->assertSame('inline; filename="DANFE-1.pdf"', $resp->headers->get('Content-Disposition'));
        $this->assertStringStartsWith('%PDF', (string) $resp->getContent());
    }

    public function test_nota_cancelada_tambem_tem_pdf(): void
    {
        $id = $this->nota(StatusNota::Cancelada, cancelada: true);

        $this->spaComo($this->admin)->get("/api/app/notas/{$id}/pdf")->assertOk();
    }

    public function test_papel_leitura_pode_abrir(): void
    {
        $leitura = Usuario::factory()->for($this->tenant)->create(['papel' => Papel::Leitura]);
        $id = $this->nota(StatusNota::Autorizada);

        $this->spaComo($leitura)->get("/api/app/notas/{$id}/pdf")->assertOk();
    }

    public function test_rascunho_e_nota_sem_xml_dao_409(): void
    {
        $rascunho = $this->nota(StatusNota::Rascunho);
        $semXml = $this->nota(StatusNota::Autorizada, comXml: false);

        $this->spaComo($this->admin)->getJson("/api/app/notas/{$rascunho}/pdf")->assertStatus(409)->assertJsonPath('codigo', 'NOTA_SEM_PDF');
        $this->spaComo($this->admin)->getJson("/api/app/notas/{$semXml}/pdf")->assertStatus(409)->assertJsonPath('codigo', 'NOTA_SEM_PDF');
    }

    public function test_sem_login_da_401(): void
    {
        $id = $this->nota(StatusNota::Autorizada);

        $this->spa()->getJson("/api/app/notas/{$id}/pdf")->assertUnauthorized();
    }

    public function test_isolamento_entre_tenants(): void
    {
        $id = $this->nota(StatusNota::Autorizada);
        $outro = Tenant::factory()->for(Plano::factory())->create();
        $intruso = Usuario::factory()->for($outro)->create(['papel' => Papel::Admin]);

        $this->spaComo($intruso)->getJson("/api/app/notas/{$id}/pdf")->assertNotFound();
    }

    public function test_nfce_autorizada_abre_o_cupom_inline(): void
    {
        $id = $this->nota(StatusNota::Autorizada, modelo: ModeloDocumento::Nfce);

        $resp = $this->spaComo($this->admin)->get("/api/app/notas/{$id}/pdf")->assertOk();

        $this->assertStringContainsString('application/pdf', (string) $resp->headers->get('Content-Type'));
        $this->assertSame('inline; filename="CUPOM-1.pdf"', $resp->headers->get('Content-Disposition'));
        $this->assertStringStartsWith('%PDF', (string) $resp->getContent());
    }

    public function test_nfce_cancelada_tambem_tem_cupom(): void
    {
        $id = $this->nota(StatusNota::Cancelada, cancelada: true, modelo: ModeloDocumento::Nfce);

        $this->spaComo($this->admin)->get("/api/app/notas/{$id}/pdf")->assertOk();
    }

    public function test_nfce_rascunho_da_409(): void
    {
        $id = $this->nota(StatusNota::Rascunho, modelo: ModeloDocumento::Nfce);

        $this->spaComo($this->admin)->getJson("/api/app/notas/{$id}/pdf")->assertStatus(409)->assertJsonPath('codigo', 'NOTA_SEM_PDF');
    }

    public function test_nfce_em_contingencia_abre_o_cupom_de_duas_vias(): void
    {
        $nota = Nota::factory()->create(['emitente_id' => $this->emitente->id, 'cliente_id' => null]);
        $nota->forceFill([
            'modelo' => ModeloDocumento::Nfce, 'status' => StatusNota::Contingencia, 'ambiente' => 'HOMOLOGACAO', 'serie' => '1', 'numero' => 1,
            'chave' => str_repeat('9', 44), 'xml_enviado' => $this->xmlOfflineDeTeste(),
        ])->save();

        $resp = $this->spaComo($this->admin)->get("/api/app/notas/{$nota->id}/pdf")->assertOk();

        $this->assertSame('inline; filename="CUPOM-1.pdf"', $resp->headers->get('Content-Disposition'));
        $this->assertStringStartsWith('%PDF', (string) $resp->getContent());
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Modules\Fiscal\Domain\Enums\ModeloDocumento;
use App\Modules\Fiscal\Domain\Enums\StatusNota;
use App\Modules\Fiscal\Domain\Models\Emitente;
use App\Modules\Fiscal\Domain\Models\Nota;
use App\Modules\Identity\Domain\Enums\AmbienteDaChave;
use App\Modules\Platform\Domain\Enums\Modulo;
use App\Modules\Platform\Domain\Enums\Recurso;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\AssinaNfeDeTeste;
use Tests\Fixtures\CenarioDeApi;
use Tests\Fixtures\CenarioDeNota;
use Tests\Fixtures\XmlAutorizadoDeTeste;
use Tests\TestCase;

class NotasConsultaV1Test extends TestCase
{
    use AssinaNfeDeTeste;
    use CenarioDeApi;
    use CenarioDeNota;
    use RefreshDatabase;
    use XmlAutorizadoDeTeste;

    protected function setUp(): void
    {
        parent::setUp();
        $this->montarCenario(Modulo::Api, Modulo::FiscalNfe);
        $this->tenant->plano?->limites()->create(['recurso' => Recurso::ApiRequisicoesMin->value, 'limite' => -1]);
        [$this->chave, $this->textoDaChave] = $this->novaChave($this->admin, AmbienteDaChave::Test);
    }

    private function nota(StatusNota $status, array $campos = [], bool $cancelada = false, ?Emitente $emitente = null): Nota
    {
        ['xml' => $proc, 'chave' => $chave] = $this->nfeProcDeTeste('2', ModeloDocumento::Nfe);
        $nota = Nota::factory()->create(['emitente_id' => ($emitente ?? $this->emitente)->id, 'cliente_id' => $this->cliente->id]);
        $nota->forceFill(array_replace([
            'modelo' => ModeloDocumento::Nfe, 'status' => $status, 'ambiente' => 'HOMOLOGACAO', 'serie' => '1',
            'numero' => $status === StatusNota::Rascunho ? null : (int) Nota::query()->max('numero') + 1,
            'chave' => $status === StatusNota::Rascunho ? null : $chave, 'protocolo' => '135260000000001',
            'xml_autorizado' => in_array($status, [StatusNota::Autorizada, StatusNota::Cancelada], true) ? $proc : null,
            'xml_cancelamento' => $cancelada ? $this->nfeProcCancelado($proc, $chave) : null,
        ], $campos))->save();

        return $nota->refresh();
    }

    public function test_lista_oculta_rascunhos_e_pagina_por_cursor(): void
    {
        $this->nota(StatusNota::Rascunho);
        $ids = [];
        foreach ([StatusNota::Autorizada, StatusNota::Rejeitada, StatusNota::Autorizada] as $status) {
            $ids[] = $this->nota($status)->id;
        }

        $primeira = $this->comoApi()->getJson('/api/v1/notas?limite=2')->assertOk()->assertJsonCount(2, 'data');
        $segunda = $this->comoApi()->getJson((string) $primeira->json('links.proxima'))->assertOk()->assertJsonCount(1, 'data');

        $vistos = array_merge(array_column($primeira->json('data'), 'id'), array_column($segunda->json('data'), 'id'));
        $this->assertEqualsCanonicalizing($ids, $vistos);
        $this->assertNull($segunda->json('links.proxima'));
    }

    public function test_filtros_de_status_modelo_e_data(): void
    {
        $this->nota(StatusNota::Autorizada);
        $rejeitada = $this->nota(StatusNota::Rejeitada, ['cstat' => '539', 'motivo' => 'Duplicidade']);
        $antiga = $this->nota(StatusNota::Autorizada);
        $antiga->forceFill(['created_at' => now()->subDays(10)])->save();

        $this->assertSame([$rejeitada->id], array_column($this->comoApi()->getJson('/api/v1/notas?status=REJEITADA')->json('data'), 'id'));
        $this->assertCount(0, $this->comoApi()->getJson('/api/v1/notas?modelo=NFCE')->json('data'));
        $this->assertCount(2, $this->comoApi()->getJson('/api/v1/notas?de='.now()->subDays(2)->toDateString())->json('data'));
        $this->comoApi()->getJson('/api/v1/notas?status=XYZ')->assertUnprocessable()->assertJsonPath('codigo', 'VALIDACAO');
        $this->comoApi()->getJson('/api/v1/notas?de=ontem')->assertUnprocessable();
        $this->comoApi()->getJson('/api/v1/notas?atualizado_desde='.urlencode(now()->addDay()->toIso8601String()))->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_show_traz_a_rejeicao_da_autoridade_no_recurso(): void
    {
        $nota = $this->nota(StatusNota::Rejeitada, ['cstat' => '539', 'motivo' => 'Duplicidade de NF-e']);

        $this->comoApi()->getJson("/api/v1/notas/{$nota->id}")->assertOk()
            ->assertJsonPath('data.status', 'REJEITADA')->assertJsonPath('data.cstat', '539')->assertJsonPath('data.motivo', 'Duplicidade de NF-e')
            ->assertJsonStructure(['data' => ['id', 'chave', 'numero', 'serie', 'ambiente', 'itens', 'pagamentos', 'eventos']]);
    }

    public function test_status_leve(): void
    {
        $nota = $this->nota(StatusNota::Autorizada);

        $this->comoApi()->getJson("/api/v1/notas/{$nota->id}/status")->assertOk()->assertJsonPath('data.status', 'AUTORIZADA')->assertJsonPath('data.chave', $nota->chave);
    }

    public function test_pdf_e_xml_da_nota_autorizada(): void
    {
        $nota = $this->nota(StatusNota::Autorizada);

        $pdf = $this->comoApi()->get("/api/v1/notas/{$nota->id}/pdf")->assertOk();
        $this->assertStringContainsString('application/pdf', (string) $pdf->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF', (string) $pdf->getContent());

        $xml = $this->comoApi()->get("/api/v1/notas/{$nota->id}/xml")->assertOk();
        $this->assertStringContainsString('application/xml', (string) $xml->headers->get('Content-Type'));
        $this->assertStringContainsString('nfeProc', (string) $xml->getContent());
    }

    public function test_xml_de_nota_nao_autorizada_e_erro_no_formato_da_api(): void
    {
        $nota = $this->nota(StatusNota::Rejeitada);

        $resposta = $this->comoApi()->getJson("/api/v1/notas/{$nota->id}/xml")->assertStatus(409);
        $this->assertStringStartsWith('application/problem+json', (string) $resposta->headers->get('Content-Type'));
    }

    public function test_xml_de_cancelamento(): void
    {
        $cancelada = $this->nota(StatusNota::Cancelada, cancelada: true);
        $autorizada = $this->nota(StatusNota::Autorizada);

        $this->comoApi()->get("/api/v1/notas/{$cancelada->id}/xml-cancelamento")->assertOk();
        $this->comoApi()->getJson("/api/v1/notas/{$autorizada->id}/xml-cancelamento")->assertStatus(409);
    }

    public function test_rascunho_e_404_em_todos_os_endpoints(): void
    {
        $rascunho = $this->nota(StatusNota::Rascunho);

        foreach (['', '/status', '/pdf', '/xml', '/xml-cancelamento'] as $sufixo) {
            $resposta = $this->comoApi()->getJson("/api/v1/notas/{$rascunho->id}{$sufixo}")->assertNotFound();
            $this->assertStringStartsWith('application/problem+json', (string) $resposta->headers->get('Content-Type'));
        }
        $this->comoApi()->getJson('/api/v1/notas/abc')->assertNotFound();
    }

    public function test_isolamento_nota_de_outro_tenant_e_404_em_todos_os_endpoints(): void
    {
        $alheia = $this->emOutroTenant(function ($outro): Nota {
            $emitente = Emitente::factory()->completo()->create();

            return $this->nota(StatusNota::Autorizada, ['numero' => 99], false, $emitente);
        });

        $lista = $this->comoApi()->getJson('/api/v1/notas')->assertOk()->assertJsonCount(0, 'data');
        $this->assertStringNotContainsString($alheia->id, $lista->getContent() ?: '');
        foreach (['', '/status', '/pdf', '/xml', '/xml-cancelamento'] as $sufixo) {
            $resposta = $this->comoApi()->getJson("/api/v1/notas/{$alheia->id}{$sufixo}")->assertNotFound();
            $this->assertStringStartsWith('application/problem+json', (string) $resposta->headers->get('Content-Type'));
        }
    }
}

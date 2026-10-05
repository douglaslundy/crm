<?php

declare(strict_types=1);

namespace Tests\Feature\Fiscal;

use App\Modules\Fiscal\Domain\Contracts\EmissorDeNfe;
use App\Modules\Fiscal\Domain\Emissao\ResultadoDeConsulta;
use App\Modules\Fiscal\Domain\Emissao\ResultadoDeEmissao;
use App\Modules\Fiscal\Domain\Enums\ModeloDocumento;
use App\Modules\Fiscal\Domain\Enums\StatusNota;
use App\Modules\Fiscal\Domain\Models\EmitenteSerie;
use App\Modules\Fiscal\Domain\Models\Nota;
use App\Modules\Platform\Application\EntitlementService;
use App\Modules\Platform\Domain\Enums\Modulo;
use App\Modules\Platform\Domain\Enums\Recurso;
use App\Modules\Tenancy\Domain\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Tests\Fixtures\CenarioDeNota;
use Tests\Fixtures\EmissorFake;
use Tests\TestCase;

class FluxoNfceTest extends TestCase
{
    use CenarioDeNota;
    use RefreshDatabase;

    private EmissorFake $emissor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->montarCenario(Modulo::FiscalNfe, Modulo::FiscalNfce);
        $this->emissor = new EmissorFake;
        $this->app->instance(EmissorDeNfe::class, $this->emissor);
        EmitenteSerie::factory()->for($this->emitente)->create(['modelo' => ModeloDocumento::Nfce, 'serie' => '1', 'proximo_numero' => 1]);
        $this->emitente->update([
            'csc_id_homologacao' => '000001',
            'csc_token_homologacao_encrypted' => Crypt::encryptString('TOKEN-DE-TESTE'),
        ]);
    }

    /** @return array<string, mixed> */
    private function corpoNfce(): array
    {
        return [
            'modelo' => 'NFCE', 'cliente_id' => null, 'consumidor_final' => null, 'desconto_centavos' => 0, 'informacoes_complementares' => null,
            'itens' => [['produto_id' => $this->produto->id, 'quantidade' => '1', 'valor_unitario_centavos' => null]],
            'pagamentos' => [['tpag' => '01', 'xpag' => null, 'valor_centavos' => 1000]],
        ];
    }

    private function emitirNfce(): string
    {
        $id = $this->spaComo($this->admin)->postJson('/api/app/notas', $this->corpoNfce())->assertCreated()->json('data.id');
        $this->spaComo($this->admin)->postJson("/api/app/notas/{$id}/emitir")->assertStatus(202);

        return $id;
    }

    public function test_emite_nfce_sem_cliente_do_rascunho_a_autorizada(): void
    {
        $id = $this->emitirNfce();

        $this->spaComo($this->admin)->getJson("/api/app/notas/{$id}")->assertOk()
            ->assertJsonPath('data.modelo', 'NFCE')->assertJsonPath('data.status', 'AUTORIZADA')
            ->assertJsonPath('data.numero', 1)->assertJsonPath('data.serie', '1');
    }

    public function test_nfce_nao_consome_a_numeracao_da_nfe(): void
    {
        $this->emitirNfce();

        $this->assertSame(2, (int) EmitenteSerie::query()->where('modelo', ModeloDocumento::Nfce)->firstOrFail()->proximo_numero);
        $this->assertSame(1, (int) EmitenteSerie::query()->where('modelo', ModeloDocumento::Nfe)->firstOrFail()->proximo_numero);
    }

    public function test_uso_denegado_fica_rejeitada_com_cstat_e_motivo_visiveis(): void
    {
        $this->emissor->enfileirarEmissao(ResultadoDeEmissao::rejeitada('110', 'Uso Denegado'));

        $id = $this->emitirNfce();

        $this->spaComo($this->admin)->getJson("/api/app/notas/{$id}")->assertOk()
            ->assertJsonPath('data.status', 'REJEITADA')->assertJsonPath('data.cstat', '110')->assertJsonPath('data.motivo', 'Uso Denegado');
    }

    public function test_reconciliacao_resolve_nfce_processando(): void
    {
        $nota = Nota::factory()->create(['emitente_id' => $this->emitente->id, 'cliente_id' => null]);
        $nota->forceFill([
            'modelo' => ModeloDocumento::Nfce, 'status' => StatusNota::Processando, 'ambiente' => 'HOMOLOGACAO', 'serie' => '1', 'numero' => 40,
            'chave' => str_repeat('6', 44), 'xml_enviado' => '<NFe>x</NFe>', 'iniciada_em' => now()->subMinutes(30),
        ])->save();
        $this->emissor->enfileirarConsulta(ResultadoDeConsulta::autorizada('135000000000010', '<nfeProc>x</nfeProc>'));

        $this->artisan('fiscal:reconciliar-processando')->assertSuccessful();
        app(TenantContext::class)->set($this->tenant->id);

        $this->assertSame(StatusNota::Autorizada, $nota->refresh()->status);
        $this->assertSame(ModeloDocumento::Nfce, $nota->modelo);
    }

    public function test_nfce_autorizada_em_producao_conta_na_cota_de_documentos(): void
    {
        $nota = Nota::factory()->create(['emitente_id' => $this->emitente->id, 'cliente_id' => null]);
        $nota->forceFill(['modelo' => ModeloDocumento::Nfce, 'status' => StatusNota::Autorizada, 'ambiente' => 'PRODUCAO', 'emitido_em' => now()])->save();

        $this->assertSame(1, app(EntitlementService::class)->uso($this->tenant, Recurso::DocumentosMes));
    }
}

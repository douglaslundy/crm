<?php

declare(strict_types=1);

namespace Tests\Feature\Fiscal;

use App\Modules\Fiscal\Application\Jobs\EmitirNfseJob;
use App\Modules\Fiscal\Domain\Enums\AmbienteFiscal;
use App\Modules\Fiscal\Domain\Enums\CertificadoStatus;
use App\Modules\Fiscal\Domain\Enums\ModeloDocumento;
use App\Modules\Fiscal\Domain\Enums\StatusNota;
use App\Modules\Fiscal\Domain\Models\EmitenteSerie;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Testing\TestResponse;
use Tests\Fixtures\CenarioDeNfse;
use Tests\TestCase;

class IniciarEmissaoNfseTest extends TestCase
{
    use CenarioDeNfse;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->montarCenarioNfse();
        Bus::fake();
    }

    private function emitir(string $id): TestResponse
    {
        return $this->spaComo($this->admin)->postJson("/api/app/notas/{$id}/emitir");
    }

    public function test_emitir_aloca_o_ndps_da_serie_dps_e_despacha_o_job_da_nfse(): void
    {
        $nota = $this->rascunhoDeNfse();

        $this->emitir($nota->id)->assertStatus(202)->assertJsonPath('data.status', 'PROCESSANDO');

        $nota = $nota->refresh();
        $this->assertSame(StatusNota::Processando, $nota->status);
        $this->assertSame(1, $nota->numero);
        $this->assertSame('1', $nota->serie);
        $this->assertSame(AmbienteFiscal::Homologacao->value, $nota->ambiente);
        $this->assertSame(2, EmitenteSerie::query()->where('modelo', ModeloDocumento::Dps)->firstOrFail()->proximo_numero);
        Bus::assertDispatched(EmitirNfseJob::class, fn (EmitirNfseJob $j): bool => $j->notaId === $nota->id);
    }

    public function test_emitir_de_novo_nao_aloca_outro_numero_nem_despacha_duas_vezes(): void
    {
        $nota = $this->rascunhoDeNfse();
        $this->emitir($nota->id)->assertStatus(202);

        $this->emitir($nota->id)->assertStatus(202);

        $this->assertSame(1, $nota->refresh()->numero);
        Bus::assertDispatchedTimes(EmitirNfseJob::class, 1);
    }

    public function test_sem_modulo_nfse_bloqueia_a_emissao(): void
    {
        $nota = $this->rascunhoDeNfse();
        $this->removerModuloNfse();

        $this->emitir($nota->id)->assertStatus(403)->assertJsonPath('codigo', 'MODULO_NAO_CONTRATADO');
        Bus::assertNotDispatched(EmitirNfseJob::class);
    }

    public function test_regime_normal_e_regime_ausente_bloqueiam(): void
    {
        $nota = $this->rascunhoDeNfse();

        $this->emitente->update(['regime_tributario' => 'NORMAL']);
        $this->emitir($nota->id)->assertStatus(422)->assertJsonPath('codigo', 'FISCAL_REGIME_NAO_SUPORTADO');

        $this->emitente->update(['regime_tributario' => null]);
        $this->emitir($nota->id)->assertStatus(422)->assertJsonPath('codigo', 'FISCAL_EMITENTE_INCOMPLETO');
    }

    public function test_sem_codigo_ibge_certificado_valido_ou_serie_dps_bloqueia(): void
    {
        $nota = $this->rascunhoDeNfse();

        $this->emitente->update(['codigo_ibge' => null]);
        $this->emitir($nota->id)->assertStatus(422)->assertJsonPath('codigo', 'FISCAL_EMITENTE_INCOMPLETO');
        $this->emitente->update(['codigo_ibge' => '3550308', 'certificado_status' => CertificadoStatus::Invalido]);
        $this->emitir($nota->id)->assertStatus(422)->assertJsonPath('codigo', 'FISCAL_CERTIFICADO_INVALIDO');
        $this->emitente->update(['certificado_status' => CertificadoStatus::Valido]);
        EmitenteSerie::query()->where('modelo', ModeloDocumento::Dps)->delete();
        $this->emitir($nota->id)->assertStatus(422)->assertJsonPath('codigo', 'FISCAL_SERIE_NAO_CONFIGURADA');
        Bus::assertNotDispatched(EmitirNfseJob::class);
    }

    public function test_a_ie_e_o_endereco_do_emitente_nao_sao_exigidos_para_a_nfse(): void
    {
        $nota = $this->rascunhoDeNfse();
        $this->emitente->update(['inscricao_estadual' => null, 'logradouro' => null, 'cep' => null]);

        $this->emitir($nota->id)->assertStatus(202);
    }
}

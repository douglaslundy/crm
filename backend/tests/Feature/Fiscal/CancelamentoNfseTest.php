<?php

declare(strict_types=1);

namespace Tests\Feature\Fiscal;

use App\Modules\Fiscal\Domain\Contracts\EmissorDeNfse;
use App\Modules\Fiscal\Domain\Emissao\ResultadoDeCancelamento;
use App\Modules\Fiscal\Domain\Enums\StatusNota;
use App\Modules\Fiscal\Domain\Models\Nota;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use RuntimeException;
use Tests\Fixtures\CenarioDeNfse;
use Tests\Fixtures\EmissorDeNfseFake;
use Tests\TestCase;

class CancelamentoNfseTest extends TestCase
{
    use CenarioDeNfse;
    use RefreshDatabase;

    private const JUSTIFICATIVA = 'Erro na emissão da nota fiscal';

    private EmissorDeNfseFake $emissor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->montarCenarioNfse();
        $this->emissor = new EmissorDeNfseFake;
        $this->app->instance(EmissorDeNfse::class, $this->emissor);
    }

    private function autorizada(): Nota
    {
        return $this->notaDeNfsePronta(StatusNota::Autorizada, [
            'chave' => EmissorDeNfseFake::CHAVE, 'numero_nfse' => '1', 'xml_autorizado' => '<NFSe>x</NFSe>', 'emitido_em' => now(), 'cstat' => '100',
        ]);
    }

    private function cancelar(Nota $nota, string $justificativa = self::JUSTIFICATIVA): TestResponse
    {
        return $this->spaComo($this->admin)->postJson("/api/app/notas/{$nota->id}/cancelar", ['justificativa' => $justificativa]);
    }

    public function test_cancela_a_nfse_pelo_evento_sem_exigir_protocolo(): void
    {
        $nota = $this->autorizada();
        $this->assertNull($nota->protocolo);

        $this->cancelar($nota)->assertStatus(202);

        $nota = $nota->refresh();
        $this->assertSame(StatusNota::Cancelada, $nota->status);
        $this->assertSame(self::JUSTIFICATIVA, $nota->cancelamento_justificativa);
        $this->assertSame('101101', $nota->cancelamento_cstat);
        $this->assertNull($nota->cancelamento_protocolo);
        $nota->makeVisible('xml_cancelamento');
        $this->assertSame('<evento>fake</evento>', $nota->xml_cancelamento);
        $this->assertSame(['cancelar'], $this->emissor->chamadas);
    }

    public function test_recusa_da_adn_volta_a_autorizada_com_o_motivo(): void
    {
        $this->emissor->enfileirarCancelamento(ResultadoDeCancelamento::recusado('E0301', 'Prazo de cancelamento expirado'));
        $nota = $this->autorizada();

        $this->cancelar($nota)->assertStatus(202);

        $nota = $nota->refresh();
        $this->assertSame(StatusNota::Autorizada, $nota->status);
        $this->assertSame('E0301', $nota->cancelamento_cstat);
        $this->assertSame('Prazo de cancelamento expirado', $nota->cancelamento_motivo);
    }

    public function test_falha_de_rede_mantem_cancelando_para_a_reconciliacao(): void
    {
        $this->emissor->falharEmCancelar = new RuntimeException('timeout');
        $nota = $this->autorizada();

        $this->cancelar($nota)->assertStatus(202);

        $this->assertSame(StatusNota::Cancelando, $nota->refresh()->status);
    }

    public function test_justificativa_curta_da_422(): void
    {
        $this->cancelar($this->autorizada(), 'curta')->assertStatus(422);
        $this->assertSame([], $this->emissor->chamadas);
    }

    public function test_so_cancela_nota_autorizada_com_chave_e_xml(): void
    {
        $this->cancelar($this->notaDeNfsePronta(StatusNota::Rascunho))->assertStatus(409)->assertJsonPath('codigo', 'CANCELAMENTO_NAO_PERMITIDO');

        $semXml = $this->notaDeNfsePronta(StatusNota::Autorizada, ['chave' => EmissorDeNfseFake::CHAVE]);
        $this->cancelar($semXml)->assertStatus(409)->assertJsonPath('codigo', 'CANCELAMENTO_NAO_PERMITIDO');
        $this->assertSame([], $this->emissor->chamadas);
    }

    public function test_sem_modulo_nfse_bloqueia_o_cancelamento(): void
    {
        $nota = $this->autorizada();
        $this->removerModuloNfse();

        $this->cancelar($nota)->assertStatus(403)->assertJsonPath('codigo', 'MODULO_NAO_CONTRATADO');
        $this->assertSame([], $this->emissor->chamadas);
    }
}

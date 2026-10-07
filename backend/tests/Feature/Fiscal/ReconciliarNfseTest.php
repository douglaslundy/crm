<?php

declare(strict_types=1);

namespace Tests\Feature\Fiscal;

use App\Modules\Fiscal\Domain\Contracts\EmissorDeNfse;
use App\Modules\Fiscal\Domain\Emissao\ResultadoDeConsultaNfse;
use App\Modules\Fiscal\Domain\Enums\StatusNota;
use App\Modules\Fiscal\Domain\Models\Nota;
use App\Modules\Tenancy\Domain\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\CenarioDeNfse;
use Tests\Fixtures\EmissorDeNfseFake;
use Tests\TestCase;

class ReconciliarNfseTest extends TestCase
{
    use CenarioDeNfse;
    use RefreshDatabase;

    private EmissorDeNfseFake $emissor;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-10-07 12:00:00');
        $this->montarCenarioNfse();
        $this->emissor = new EmissorDeNfseFake;
        $this->app->instance(EmissorDeNfse::class, $this->emissor);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function reconciliar(): void
    {
        $this->artisan('fiscal:reconciliar-processando')->assertSuccessful();
        // O comando limpa o contexto do tenant ao terminar; o teste volta a ele para continuar consultando.
        app(TenantContext::class)->set($this->tenant->id);
    }

    private function processando(int $minutosAtras): Nota
    {
        return $this->notaDeNfsePronta(StatusNota::Processando, ['iniciada_em' => now()->subMinutes($minutosAtras)]);
    }

    private function cancelando(int $minutosAtras): Nota
    {
        return $this->notaDeNfsePronta(StatusNota::Cancelando, [
            'chave' => EmissorDeNfseFake::CHAVE, 'xml_autorizado' => '<NFSe>x</NFSe>', 'cancelamento_solicitado_em' => now()->subMinutes($minutosAtras),
            'cancelamento_justificativa' => 'Erro na emissão da nota fiscal',
        ]);
    }

    public function test_dps_autorizada_na_adn_concilia_a_nota(): void
    {
        $nota = $this->processando(30);
        $this->emissor->enfileirarConsultaDps(ResultadoDeConsultaNfse::autorizada(EmissorDeNfseFake::CHAVE, '31', $this->xmlDaNfse()));

        $this->reconciliar();

        $nota = $nota->refresh();
        $this->assertSame(StatusNota::Autorizada, $nota->status);
        $this->assertSame('31', $nota->numero_nfse);
        $this->assertSame(EmissorDeNfseFake::CHAVE, $nota->chave);
    }

    public function test_dps_nao_encontrada_vira_erro_so_depois_do_prazo(): void
    {
        $velha = $this->processando(30);
        $this->reconciliar();
        $this->assertSame(StatusNota::Erro, $velha->refresh()->status);
        $this->assertStringContainsString('A ADN não recebeu a DPS', (string) $velha->mensagem_erro);

        Nota::query()->delete();
        $nova = $this->processando(2);
        $this->reconciliar();
        $this->assertSame(StatusNota::Processando, $nova->refresh()->status);
    }

    public function test_incerteza_na_consulta_mantem_a_nota_processando(): void
    {
        $nota = $this->processando(30);
        $this->emissor->enfileirarConsultaDps(ResultadoDeConsultaNfse::erro('ADN fora do ar'));

        $this->reconciliar();

        $this->assertSame(StatusNota::Processando, $nota->refresh()->status);
    }

    public function test_cancelamento_confirmado_na_adn_cancela_a_nota(): void
    {
        $nota = $this->cancelando(2);
        $this->emissor->enfileirarConsultaPorChave(ResultadoDeConsultaNfse::cancelada(EmissorDeNfseFake::CHAVE));

        $this->reconciliar();

        $this->assertSame(StatusNota::Cancelada, $nota->refresh()->status);
        $this->assertNotNull($nota->cancelado_em);
    }

    public function test_ainda_autorizada_volta_so_depois_do_prazo(): void
    {
        $velha = $this->cancelando(30);
        $this->reconciliar();
        $this->assertSame(StatusNota::Autorizada, $velha->refresh()->status);
        $this->assertStringContainsString('Cancelamento não confirmado', (string) $velha->cancelamento_motivo);

        Nota::query()->delete();
        $nova = $this->cancelando(2);
        $this->reconciliar();
        $this->assertSame(StatusNota::Cancelando, $nova->refresh()->status);
    }

    public function test_incerteza_na_consulta_do_cancelamento_mantem_cancelando(): void
    {
        $nota = $this->cancelando(30);
        $this->emissor->enfileirarConsultaPorChave(ResultadoDeConsultaNfse::erro('ADN fora do ar'));

        $this->reconciliar();

        $this->assertSame(StatusNota::Cancelando, $nota->refresh()->status);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature\Fiscal;

use App\Modules\Fiscal\Domain\Contracts\EmissorDeNfe;
use App\Modules\Fiscal\Domain\Emissao\ResultadoDeConsulta;
use App\Modules\Fiscal\Domain\Enums\StatusNota;
use App\Modules\Fiscal\Domain\Models\Nota;
use App\Modules\Tenancy\Domain\Models\Tenant;
use App\Modules\Tenancy\Domain\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\CenarioDeNota;
use Tests\Fixtures\EmissorFake;
use Tests\TestCase;

class ReconciliarNotasTest extends TestCase
{
    use CenarioDeNota;
    use RefreshDatabase;

    private EmissorFake $emissor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->montarCenario();
        $this->emissor = new EmissorFake;
        $this->app->instance(EmissorDeNfe::class, $this->emissor);
    }

    private function processando(?string $chave, int $minutosAtras): Nota
    {
        $nota = Nota::factory()->create(['emitente_id' => $this->emitente->id, 'cliente_id' => $this->cliente->id]);
        $nota->forceFill([
            'status' => StatusNota::Processando, 'ambiente' => 'HOMOLOGACAO', 'serie' => '1', 'numero' => random_int(1, 9999),
            'chave' => $chave, 'xml_enviado' => $chave === null ? null : '<NFe>x</NFe>', 'iniciada_em' => now()->subMinutes($minutosAtras),
        ])->save();

        return $nota;
    }

    private function rodar(): void
    {
        $this->artisan('fiscal:reconciliar-processando')->assertSuccessful();
        app(TenantContext::class)->set($this->tenant->id);
    }

    public function test_concilia_nota_autorizada_na_sefaz(): void
    {
        $nota = $this->processando(str_repeat('1', 44), 30);
        $this->emissor->enfileirarConsulta(ResultadoDeConsulta::autorizada('135000000000010', '<nfeProc>x</nfeProc>'));

        $this->rodar();

        $this->assertSame(StatusNota::Autorizada, $nota->refresh()->status);
        $this->assertSame('135000000000010', $nota->protocolo);
    }

    public function test_marca_cancelada_quando_a_sefaz_diz_cancelada(): void
    {
        $nota = $this->processando(str_repeat('2', 44), 30);
        $this->emissor->enfileirarConsulta(ResultadoDeConsulta::cancelada());
        $this->rodar();
        $this->assertSame(StatusNota::Cancelada, $nota->refresh()->status);
    }

    public function test_nao_encontrada_depois_do_prazo_vira_erro_e_preserva_o_numero(): void
    {
        $nota = $this->processando(str_repeat('3', 44), 30);
        $numero = $nota->numero;
        $this->emissor->enfileirarConsulta(ResultadoDeConsulta::naoEncontrada());
        $this->rodar();

        $this->assertSame(StatusNota::Erro, $nota->refresh()->status);
        $this->assertSame($numero, $nota->numero);
    }

    public function test_nao_encontrada_dentro_do_prazo_continua_processando(): void
    {
        $nota = $this->processando(str_repeat('4', 44), 2);
        $this->emissor->enfileirarConsulta(ResultadoDeConsulta::naoEncontrada());
        $this->rodar();
        $this->assertSame(StatusNota::Processando, $nota->refresh()->status);
    }

    public function test_nunca_enviada_depois_do_prazo_vira_erro_sem_consultar(): void
    {
        $nota = $this->processando(null, 30);
        $this->rodar();

        $this->assertSame(StatusNota::Erro, $nota->refresh()->status);
        $this->assertStringContainsString('não chegou a ser enviada', (string) $nota->mensagem_erro);
        $this->assertSame([], $this->emissor->chamadas);
    }

    public function test_nunca_enviada_dentro_do_prazo_e_deixada_em_paz(): void
    {
        $nota = $this->processando(null, 3);
        $this->rodar();
        $this->assertSame(StatusNota::Processando, $nota->refresh()->status);
    }

    public function test_erro_na_consulta_nao_muda_a_nota(): void
    {
        $nota = $this->processando(str_repeat('5', 44), 30);
        $this->emissor->enfileirarConsulta(ResultadoDeConsulta::erro('timeout'));
        $this->rodar();
        $this->assertSame(StatusNota::Processando, $nota->refresh()->status);
    }

    public function test_a_consulta_usa_o_ambiente_da_nota_e_nao_o_do_emitente(): void
    {
        $this->emitente->update(['ambiente_fiscal' => 'PRODUCAO']);
        $nota = $this->processando(str_repeat('7', 44), 30); // a nota é de HOMOLOGACAO
        $this->emissor->enfileirarConsulta(ResultadoDeConsulta::naoEncontrada());

        $this->rodar();

        $this->assertSame(['HOMOLOGACAO'], $this->emissor->ambientes);
        $this->assertSame(StatusNota::Erro, $nota->refresh()->status);
    }

    public function test_varre_notas_de_todos_os_tenants_sem_vazar_contexto(): void
    {
        $nota = $this->processando(str_repeat('6', 44), 30);
        $outro = Tenant::factory()->create();
        app(TenantContext::class)->set($outro->id);
        // contexto do outro tenant ativo: o comando precisa enxergar a nota do primeiro mesmo assim
        $this->emissor->enfileirarConsulta(ResultadoDeConsulta::autorizada('135000000000011', '<nfeProc/>'));

        $this->artisan('fiscal:reconciliar-processando')->assertSuccessful();

        $this->assertSame(StatusNota::Autorizada, Nota::withoutTenantScope()->findOrFail($nota->id)->status);
        $this->assertNull(app(TenantContext::class)->id(), 'o comando limpa o contexto');
    }
}

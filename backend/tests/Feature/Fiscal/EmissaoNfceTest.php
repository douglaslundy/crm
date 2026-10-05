<?php

declare(strict_types=1);

namespace Tests\Feature\Fiscal;

use App\Modules\Customers\Domain\Enums\EstagioCliente;
use App\Modules\Customers\Domain\Enums\TipoCliente;
use App\Modules\Customers\Domain\Models\Cliente;
use App\Modules\Fiscal\Application\Actions\IniciarEmissao;
use App\Modules\Fiscal\Application\Jobs\EmitirNotaJob;
use App\Modules\Fiscal\Domain\Enums\ModeloDocumento;
use App\Modules\Fiscal\Domain\Enums\StatusNota;
use App\Modules\Fiscal\Domain\Exceptions\EmissaoBloqueadaException;
use App\Modules\Fiscal\Domain\Models\EmitenteSerie;
use App\Modules\Fiscal\Domain\Models\Nota;
use App\Modules\Platform\Domain\Enums\Modulo;
use App\Modules\Platform\Domain\Exceptions\ModuloNaoContratadoException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Queue;
use Tests\Fixtures\CenarioDeNota;
use Tests\TestCase;

class EmissaoNfceTest extends TestCase
{
    use CenarioDeNota;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->montarCenario(Modulo::FiscalNfe, Modulo::FiscalNfce);
        EmitenteSerie::factory()->for($this->emitente)->create(['modelo' => ModeloDocumento::Nfce, 'serie' => '1', 'proximo_numero' => 1]);
        $this->emitente->update([
            'csc_id_homologacao' => '000001',
            'csc_token_homologacao_encrypted' => Crypt::encryptString('TOKEN-DE-TESTE'),
        ]);
    }

    private function nota(ModeloDocumento $modelo, ?Cliente $cliente): Nota
    {
        $nota = Nota::factory()->create(['emitente_id' => $this->emitente->id, 'cliente_id' => $cliente?->id]);
        $nota->forceFill(['modelo' => $modelo, 'subtotal_centavos' => 1000, 'total_centavos' => 1000])->save();
        $nota->itens()->create(['produto_id' => $this->produto->id, 'ordem' => 1, 'quantidade_milesimos' => 1000, 'valor_unitario_centavos' => 1000, 'total_centavos' => 1000]);
        $nota->pagamentos()->create(['tpag' => '01', 'xpag' => null, 'valor_centavos' => 1000]);

        return $nota->refresh();
    }

    /** Cliente PF sem endereço: o que a NFC-e aceita e a NF-e recusa. */
    private function consumidor(string $uf = 'SP', bool $comDocumento = true): Cliente
    {
        $fabrica = $comDocumento ? Cliente::factory()->comCpfValido() : Cliente::factory();
        $dados = [
            'nome' => 'Joana Consumidora', 'estagio' => EstagioCliente::Cliente, 'tipo' => TipoCliente::Pf, 'uf' => $uf,
            'logradouro' => null, 'numero' => null, 'bairro' => null, 'cidade' => null, 'cep' => null, 'codigo_ibge' => null,
        ];
        if (! $comDocumento) {
            $dados['cpf_cnpj'] = null;
        }

        return $fabrica->create($dados);
    }

    private function proximoNumero(ModeloDocumento $modelo): int
    {
        return (int) EmitenteSerie::query()->where('modelo', $modelo)->firstOrFail()->proximo_numero;
    }

    public function test_nfce_sem_cliente_emite_com_a_serie_do_modelo_e_cfop_interno(): void
    {
        $nota = app(IniciarEmissao::class)->executar($this->nota(ModeloDocumento::Nfce, null)->id, $this->admin);

        $this->assertSame(StatusNota::Processando, $nota->status);
        $this->assertSame(1, $nota->numero);
        $this->assertNull($nota->destinatario);
        $this->assertSame('5102', $nota->itens()->first()->cfop);
        $this->assertSame(2, $this->proximoNumero(ModeloDocumento::Nfce));
        $this->assertSame(1, $this->proximoNumero(ModeloDocumento::Nfe));
        Queue::assertPushed(EmitirNotaJob::class);
    }

    public function test_nfce_com_cliente_pf_sem_endereco_nao_bloqueia_e_leva_so_documento_e_nome(): void
    {
        $cliente = $this->consumidor();
        $nota = app(IniciarEmissao::class)->executar($this->nota(ModeloDocumento::Nfce, $cliente)->id, $this->admin);

        $this->assertSame('PF', $nota->destinatario['tipo']);
        $this->assertSame(preg_replace('/\D/', '', (string) $cliente->cpf_cnpj), $nota->destinatario['documento']);
        $this->assertSame('Joana Consumidora', $nota->destinatario['nome']);
    }

    public function test_nfce_com_cliente_sem_documento_vira_venda_sem_identificacao(): void
    {
        $nota = app(IniciarEmissao::class)->executar($this->nota(ModeloDocumento::Nfce, $this->consumidor('SP', false))->id, $this->admin);

        $this->assertNull($nota->destinatario);
    }

    public function test_nfce_para_outra_uf_bloqueia_sem_consumir_numero(): void
    {
        $nota = $this->nota(ModeloDocumento::Nfce, $this->consumidor('RJ'));

        try {
            app(IniciarEmissao::class)->executar($nota->id, $this->admin);
            $this->fail('Deveria bloquear a NFC-e interestadual.');
        } catch (EmissaoBloqueadaException $e) {
            $this->assertSame('FISCAL_NFCE_INTERESTADUAL', $e->codigo());
        }
        $this->assertSame(1, $this->proximoNumero(ModeloDocumento::Nfce));
    }

    public function test_sem_csc_do_ambiente_bloqueia_antes_de_consumir_numero(): void
    {
        $this->emitente->update(['csc_id_homologacao' => null]);
        $nota = $this->nota(ModeloDocumento::Nfce, null);

        try {
            app(IniciarEmissao::class)->executar($nota->id, $this->admin);
            $this->fail('Deveria bloquear sem CSC.');
        } catch (EmissaoBloqueadaException $e) {
            $this->assertSame('FISCAL_CSC_AUSENTE', $e->codigo());
            $this->assertStringContainsString('CSC', $e->getMessage());
        }
        $this->assertSame(1, $this->proximoNumero(ModeloDocumento::Nfce));
        $this->assertSame(StatusNota::Rascunho, $nota->refresh()->status);
    }

    public function test_sem_serie_nfce_cadastrada_bloqueia(): void
    {
        EmitenteSerie::query()->where('modelo', ModeloDocumento::Nfce)->delete();

        try {
            app(IniciarEmissao::class)->executar($this->nota(ModeloDocumento::Nfce, null)->id, $this->admin);
            $this->fail('Deveria bloquear sem série.');
        } catch (EmissaoBloqueadaException $e) {
            $this->assertSame('FISCAL_SERIE_NAO_CONFIGURADA', $e->codigo());
            $this->assertStringContainsString('NFC-e', $e->getMessage());
        }
    }

    public function test_tenant_sem_modulo_nfce_nao_emite_nfce(): void
    {
        $this->tenant->plano->modulos()->where('modulo', Modulo::FiscalNfce)->delete();
        $this->tenant->plano->unsetRelation('modulos');

        $this->expectException(ModuloNaoContratadoException::class);
        app(IniciarEmissao::class)->executar($this->nota(ModeloDocumento::Nfce, null)->id, $this->admin);
    }

    public function test_nfe_continua_exigindo_endereco_e_usa_a_serie_da_nfe(): void
    {
        $nota = $this->nota(ModeloDocumento::Nfe, $this->consumidor());

        try {
            app(IniciarEmissao::class)->executar($nota->id, $this->admin);
            $this->fail('A NF-e deveria exigir o endereço.');
        } catch (EmissaoBloqueadaException $e) {
            $this->assertSame('FISCAL_DESTINATARIO_INCOMPLETO', $e->codigo());
        }

        $ok = app(IniciarEmissao::class)->executar($this->nota(ModeloDocumento::Nfe, $this->cliente)->id, $this->admin);
        $this->assertSame(1, $ok->numero);
        $this->assertSame(1, $this->proximoNumero(ModeloDocumento::Nfce));
        $this->assertSame(2, $this->proximoNumero(ModeloDocumento::Nfe));
    }
}

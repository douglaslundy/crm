<?php

declare(strict_types=1);

namespace Tests\Feature\Fiscal;

use App\Modules\Fiscal\Application\Actions\CriarVendaMista;
use App\Modules\Fiscal\Application\Actions\EmitirVendaMista;
use App\Modules\Fiscal\Application\Jobs\EmitirNfseJob;
use App\Modules\Fiscal\Application\Jobs\EmitirNotaJob;
use App\Modules\Fiscal\Domain\Enums\AmbienteFiscal;
use App\Modules\Fiscal\Domain\Enums\ModeloDocumento;
use App\Modules\Fiscal\Domain\Enums\StatusNota;
use App\Modules\Fiscal\Domain\Exceptions\VendaComNotaBloqueadaException;
use App\Modules\Fiscal\Domain\Models\EmitenteSerie;
use App\Modules\Fiscal\Domain\Models\Nota;
use App\Modules\Fiscal\Domain\Models\Venda;
use App\Modules\Identity\Domain\Enums\Papel;
use App\Modules\Identity\Domain\Models\Usuario;
use App\Modules\Shared\Domain\Exceptions\AcessoNegadoException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use RuntimeException;
use Tests\Fixtures\CenarioDeVenda;
use Tests\TestCase;

class EmitirVendaMistaTest extends TestCase
{
    use CenarioDeVenda;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->montarCenarioVenda();
        Bus::fake();
    }

    private function venda(): Venda
    {
        return app(CriarVendaMista::class)->executar($this->corpoVenda(), $this->admin);
    }

    /** @return list<string> */
    private function statusDasNotas(Venda $venda): array
    {
        return $venda->notas()->orderBy('modelo')->get()->map(fn (Nota $n): string => $n->status->value)->all();
    }

    /** Bloqueia só a NF-e (o produto fica sem NCM). */
    private function bloquearANfe(): void
    {
        $this->produto->forceFill(['ncm' => null])->save();
    }

    private function nenhumaSerieDeNumeroGasta(): bool
    {
        return EmitenteSerie::query()->get()->every(fn (EmitenteSerie $s): bool => $s->proximo_numero === 1);
    }

    public function test_emite_as_duas_notas_quando_ambas_estao_aptas(): void
    {
        $venda = $this->venda();

        $r = app(EmitirVendaMista::class)->executar($venda, $this->admin);

        $this->assertSame(['PROCESSANDO', 'PROCESSANDO'], $this->statusDasNotas($venda));
        $this->assertSame([null, null], array_column($r->notas(), 'bloqueio'));
        Bus::assertDispatchedTimes(EmitirNotaJob::class, 1);
        Bus::assertDispatchedTimes(EmitirNfseJob::class, 1);
    }

    public function test_nota_bloqueada_sem_confirmacao_nao_emite_nenhuma_e_nao_gasta_numero(): void
    {
        $venda = $this->venda();
        $this->bloquearANfe();

        try {
            app(EmitirVendaMista::class)->executar($venda, $this->admin);
            $this->fail('deveria bloquear');
        } catch (VendaComNotaBloqueadaException $e) {
            $this->assertSame('FISCAL_VENDA_NOTA_BLOQUEADA', $e->codigo());
            $this->assertSame(409, $e->status());
            $notas = collect($e->extras()['notas']);
            $this->assertNotNull($notas->firstWhere('modelo', 'NFE')['bloqueio']);
            $this->assertNull($notas->firstWhere('modelo', 'DPS')['bloqueio']);
        }
        Bus::assertNothingDispatched();
        $this->assertSame(['RASCUNHO', 'RASCUNHO'], $this->statusDasNotas($venda));
        $this->assertTrue($this->nenhumaSerieDeNumeroGasta());
    }

    public function test_com_confirmacao_emite_so_a_valida_e_devolve_o_bloqueio_da_outra(): void
    {
        $venda = $this->venda();
        $this->bloquearANfe();

        $r = app(EmitirVendaMista::class)->executar($venda, $this->admin, true);

        $this->assertSame(['PROCESSANDO', 'RASCUNHO'], $this->statusDasNotas($venda));
        $nfe = collect($r->notas())->firstWhere('modelo', 'NFE');
        $this->assertStringStartsWith('FISCAL_', $nfe['bloqueio']['codigo']);
        Bus::assertDispatchedTimes(EmitirNfseJob::class, 1);
        Bus::assertNotDispatched(EmitirNotaJob::class);
    }

    public function test_as_duas_bloqueadas_lancam_mesmo_com_confirmacao(): void
    {
        $venda = $this->venda();
        $this->bloquearANfe();
        $this->servico->forceFill(['c_trib_nac' => null])->save();

        $this->expectException(VendaComNotaBloqueadaException::class);

        app(EmitirVendaMista::class)->executar($venda, $this->admin, true);
    }

    public function test_nota_ja_processando_e_pulada_na_retentativa(): void
    {
        $venda = $this->venda();
        app(EmitirVendaMista::class)->executar($venda, $this->admin);

        $r = app(EmitirVendaMista::class)->executar($venda, $this->admin);

        $this->assertSame(['PROCESSANDO', 'PROCESSANDO'], array_column($r->notas(), 'status'));
        Bus::assertDispatchedTimes(EmitirNotaJob::class, 1);
        Bus::assertDispatchedTimes(EmitirNfseJob::class, 1);
    }

    public function test_serie_nao_configurada_bloqueia_so_a_nota_do_modelo(): void
    {
        $venda = $this->venda();
        EmitenteSerie::query()->where('modelo', 'DPS')->delete();

        $r = app(EmitirVendaMista::class)->executar($venda, $this->admin, true);

        $this->assertSame(['RASCUNHO', 'PROCESSANDO'], $this->statusDasNotas($venda));
        $this->assertSame('FISCAL_SERIE_NAO_CONFIGURADA', collect($r->notas())->firstWhere('modelo', 'DPS')['bloqueio']['codigo']);
        Bus::assertDispatchedTimes(EmitirNotaJob::class, 1);
    }

    public function test_falha_inesperada_ao_iniciar_uma_nota_nao_desfaz_nem_impede_a_outra(): void
    {
        $venda = $this->venda();
        // a NFS-e passa na verificação e quebra ao ser marcada como PROCESSANDO
        Nota::updating(function (Nota $nota): void {
            if ($nota->modelo === ModeloDocumento::Dps && $nota->status === StatusNota::Processando) {
                throw new RuntimeException('falha simulada');
            }
        });

        $r = app(EmitirVendaMista::class)->executar($venda, $this->admin, true);

        $this->assertSame(['RASCUNHO', 'PROCESSANDO'], $this->statusDasNotas($venda));
        $this->assertSame('FISCAL_VENDA_FALHA_AO_EMITIR', collect($r->notas())->firstWhere('modelo', 'DPS')['bloqueio']['codigo']);
        Bus::assertDispatchedTimes(EmitirNotaJob::class, 1);
    }

    public function test_cota_atingida_bloqueia_as_duas_antes_de_emitir(): void
    {
        $venda = $this->venda();
        $this->emitente->forceFill(['ambiente_fiscal' => AmbienteFiscal::Producao])->save();
        $this->tenant->plano->limites()->where('recurso', 'DOCUMENTOS_MES')->update(['limite' => 1]);
        $this->tenant->unsetRelation('plano');
        $this->admin->unsetRelation('tenant');
        $existente = Nota::factory()->create(['emitente_id' => $this->emitente->id, 'cliente_id' => $this->cliente->id]);
        $existente->forceFill(['status' => StatusNota::Autorizada, 'ambiente' => 'PRODUCAO', 'emitido_em' => now()])->save();

        try {
            app(EmitirVendaMista::class)->executar($venda, $this->admin);
            $this->fail('deveria bloquear');
        } catch (VendaComNotaBloqueadaException $e) {
            $this->assertSame(['LIMITE_DO_PLANO', 'LIMITE_DO_PLANO'], array_column(array_column($e->extras()['notas'], 'bloqueio'), 'codigo'));
        }
        Bus::assertNothingDispatched();
    }

    public function test_vendedor_nao_emite(): void
    {
        $venda = $this->venda();
        $vendedor = Usuario::factory()->for($this->tenant)->create(['papel' => Papel::Vendedor]);

        $this->expectException(AcessoNegadoException::class);

        app(EmitirVendaMista::class)->executar($venda, $vendedor);
    }
}

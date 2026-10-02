<?php

declare(strict_types=1);

namespace Tests\Feature\Fiscal;

use App\Modules\Fiscal\Application\Actions\IniciarCancelamento;
use App\Modules\Fiscal\Application\Jobs\CancelarNotaJob;
use App\Modules\Fiscal\Domain\Enums\StatusNota;
use App\Modules\Fiscal\Domain\Exceptions\CancelamentoNaoPermitidoException;
use App\Modules\Fiscal\Domain\Exceptions\JustificativaInvalidaException;
use App\Modules\Fiscal\Domain\Models\Nota;
use App\Modules\Identity\Domain\Enums\Papel;
use App\Modules\Identity\Domain\Models\Usuario;
use App\Modules\Platform\Domain\Exceptions\AssinaturaSemEscritaException;
use App\Modules\Platform\Domain\Exceptions\ModuloNaoContratadoException;
use App\Modules\Shared\Domain\Exceptions\AcessoNegadoException;
use App\Modules\Tenancy\Domain\Enums\SituacaoAssinatura;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Fixtures\CenarioDeNota;
use Tests\TestCase;

class IniciarCancelamentoTest extends TestCase
{
    use CenarioDeNota;
    use RefreshDatabase;

    private const JUSTIFICATIVA = 'Cancelamento por erro de digitação do valor total';

    protected function setUp(): void
    {
        parent::setUp();
        $this->montarCenario();
        Queue::fake();
    }

    private function autorizada(int $numero = 1): Nota
    {
        $nota = Nota::factory()->create(['emitente_id' => $this->emitente->id, 'cliente_id' => $this->cliente->id]);
        $nota->forceFill([
            'status' => StatusNota::Autorizada, 'ambiente' => 'HOMOLOGACAO', 'serie' => '1', 'numero' => $numero,
            'chave' => str_repeat('1', 44), 'protocolo' => '135260000000001', 'xml_autorizado' => '<nfeProc>x</nfeProc>',
            'emitido_em' => now(),
        ])->save();

        return $nota->refresh();
    }

    public function test_inicia_o_cancelamento_e_despacha_o_job(): void
    {
        $nota = $this->autorizada();

        $r = app(IniciarCancelamento::class)->executar($nota->id, '  '.self::JUSTIFICATIVA.'  ', $this->admin);

        $this->assertSame(StatusNota::Cancelando, $r->status);
        $this->assertSame(self::JUSTIFICATIVA, $r->cancelamento_justificativa);
        $this->assertNotNull($r->cancelamento_solicitado_em);
        Queue::assertPushed(CancelarNotaJob::class, fn (CancelarNotaJob $j): bool => $j->notaId === $nota->id && $j->tenantId === $this->tenant->id);
        $ultimo = $r->eventos()->get()->last();
        $this->assertSame(['AUTORIZADA', 'CANCELANDO'], [$ultimo->status_de, $ultimo->status_para]);
    }

    public function test_duplo_clique_nao_despacha_segundo_job_nem_muda_nada(): void
    {
        $nota = $this->autorizada();
        app(IniciarCancelamento::class)->executar($nota->id, self::JUSTIFICATIVA, $this->admin);
        $solicitado = $nota->refresh()->cancelamento_solicitado_em;

        $segunda = app(IniciarCancelamento::class)->executar($nota->id, 'Outra justificativa diferente da primeira', $this->admin);

        $this->assertSame(StatusNota::Cancelando, $segunda->status);
        $this->assertSame(self::JUSTIFICATIVA, $segunda->cancelamento_justificativa, 'não sobrescreve a justificativa já enviada');
        $this->assertEquals($solicitado, $segunda->cancelamento_solicitado_em);
        Queue::assertPushed(CancelarNotaJob::class, 1);
    }

    public function test_nota_ja_cancelada_devolve_sem_despachar(): void
    {
        $nota = $this->autorizada();
        $nota->forceFill(['status' => StatusNota::Cancelada])->save();

        $r = app(IniciarCancelamento::class)->executar($nota->id, self::JUSTIFICATIVA, $this->admin);

        $this->assertSame(StatusNota::Cancelada, $r->status);
        Queue::assertNothingPushed();
    }

    public function test_so_nota_autorizada_pode_ser_cancelada(): void
    {
        foreach ([StatusNota::Rascunho, StatusNota::Processando, StatusNota::Rejeitada, StatusNota::Erro] as $i => $status) {
            $nota = $this->autorizada(10 + $i);
            $nota->forceFill(['status' => $status])->save();
            try {
                app(IniciarCancelamento::class)->executar($nota->id, self::JUSTIFICATIVA, $this->admin);
                $this->fail("Cancelou uma nota {$status->value}.");
            } catch (CancelamentoNaoPermitidoException $e) {
                $this->assertSame('CANCELAMENTO_NAO_PERMITIDO', $e->codigo());
            }
        }
        Queue::assertNothingPushed();
    }

    public function test_autorizada_sem_protocolo_nao_pode_ser_cancelada(): void
    {
        $nota = $this->autorizada();
        $nota->forceFill(['protocolo' => null])->save();
        $this->expectException(CancelamentoNaoPermitidoException::class);
        app(IniciarCancelamento::class)->executar($nota->id, self::JUSTIFICATIVA, $this->admin);
    }

    public function test_justificativa_invalida_bloqueia_sem_mudar_a_nota(): void
    {
        $nota = $this->autorizada();
        try {
            app(IniciarCancelamento::class)->executar($nota->id, 'curta', $this->admin);
            $this->fail('Esperava JustificativaInvalidaException.');
        } catch (JustificativaInvalidaException) {
            $this->assertSame(StatusNota::Autorizada, $nota->refresh()->status);
            Queue::assertNothingPushed();
        }
    }

    public function test_vendedor_e_leitura_nao_cancelam(): void
    {
        $nota = $this->autorizada();
        foreach ([Papel::Vendedor, Papel::Leitura] as $papel) {
            $usuario = Usuario::factory()->for($this->tenant)->create(['papel' => $papel]);
            try {
                app(IniciarCancelamento::class)->executar($nota->id, self::JUSTIFICATIVA, $usuario);
                $this->fail("{$papel->value} cancelou.");
            } catch (AcessoNegadoException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_exige_modulo_e_assinatura_com_escrita(): void
    {
        $nota = $this->autorizada();

        $this->tenant->forceFill(['situacao' => SituacaoAssinatura::Suspensa])->save();
        try {
            app(IniciarCancelamento::class)->executar($nota->id, self::JUSTIFICATIVA, $this->admin->refresh());
            $this->fail('Assinatura suspensa cancelou.');
        } catch (AssinaturaSemEscritaException) {
            $this->addToAssertionCount(1);
        }

        $this->tenant->forceFill(['situacao' => SituacaoAssinatura::Ativa])->save();
        $this->tenant->plano->modulos()->delete();
        $this->tenant->unsetRelation('plano');
        $this->expectException(ModuloNaoContratadoException::class);
        app(IniciarCancelamento::class)->executar($nota->id, self::JUSTIFICATIVA, $this->admin->refresh());
    }
}

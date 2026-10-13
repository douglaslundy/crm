<?php

declare(strict_types=1);

namespace Tests\Feature\Crm;

use App\Modules\Crm\Application\Actions\AtualizarProposta;
use App\Modules\Crm\Application\Actions\CancelarProposta;
use App\Modules\Crm\Application\Actions\CriarProposta;
use App\Modules\Crm\Application\Actions\EnviarProposta;
use App\Modules\Crm\Domain\Enums\StatusDaProposta;
use App\Modules\Crm\Domain\Enums\StatusDoNegocio;
use App\Modules\Crm\Domain\Enums\TipoDeRegistro;
use App\Modules\Crm\Domain\Exceptions\RegraDoCrmException;
use App\Modules\Crm\Domain\Models\Etapa;
use App\Modules\Crm\Domain\Models\Funil;
use App\Modules\Crm\Domain\Models\Negocio;
use App\Modules\Crm\Domain\Models\Proposta;
use App\Modules\Crm\Domain\Models\RegistroDaTimeline;
use App\Modules\Identity\Domain\Enums\Papel;
use App\Modules\Shared\Domain\Exceptions\AcessoNegadoException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ComCrm;
use Tests\Support\ComPropostas;
use Tests\TestCase;

class PropostasActionsTest extends TestCase
{
    use ComCrm;
    use ComPropostas;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararCrm();
    }

    private function codigoDe(callable $acao): string
    {
        try {
            $acao();
        } catch (RegraDoCrmException $e) {
            return $e->codigo();
        }
        $this->fail('Deveria bloquear.');
    }

    public function test_cria_com_snapshot_dos_itens_numero_e_validade_padrao(): void
    {
        $negocio = $this->negocioComItens();

        $p = app(CriarProposta::class)->executar($this->admin, $negocio);
        $segunda = app(CriarProposta::class)->executar($this->admin, $negocio);

        $this->assertSame(StatusDaProposta::Rascunho, $p->status);
        $this->assertSame(1, $p->numero);
        $this->assertSame(2, $segunda->numero);
        $this->assertSame(15, $p->validade_dias);
        $this->assertCount(1, $p->itens);
        $this->assertSame(3000, $p->totalCentavos());
    }

    public function test_negocio_sem_itens_bloqueia(): void
    {
        $funil = Funil::factory()->padrao()->create();
        $etapa = Etapa::factory()->create(['funil_id' => $funil->id, 'posicao' => 0]);
        $negocio = Negocio::factory()->create(['funil_id' => $funil->id, 'etapa_id' => $etapa->id, 'responsavel_id' => $this->admin->id]);

        $this->assertSame('NEGOCIO_SEM_ITENS', $this->codigoDe(fn () => app(CriarProposta::class)->executar($this->admin, $negocio)));
    }

    public function test_negocio_fechado_bloqueia(): void
    {
        $negocio = $this->negocioComItens();
        $negocio->update(['status' => StatusDoNegocio::Ganho]);

        $this->assertSame('NEGOCIO_FECHADO', $this->codigoDe(fn () => app(CriarProposta::class)->executar($this->admin, $negocio)));
    }

    public function test_desconto_zero_e_valido_e_desconto_igual_ao_subtotal_bloqueia(): void
    {
        $negocio = $this->negocioComItens();

        $ok = app(CriarProposta::class)->executar($this->admin, $negocio, ['desconto_centavos' => 0]);
        $this->assertSame(0, $ok->desconto_centavos);

        $this->assertSame('PROPOSTA_DESCONTO_INVALIDO', $this->codigoDe(
            fn () => app(CriarProposta::class)->executar($this->admin, $negocio, ['desconto_centavos' => 3000]),
        ));
        $this->assertSame('PROPOSTA_DESCONTO_INVALIDO', $this->codigoDe(
            fn () => app(AtualizarProposta::class)->executar($this->admin, $ok, ['desconto_centavos' => 4000]),
        ));
    }

    public function test_atualiza_rascunho_e_so_rascunho(): void
    {
        $p = app(CriarProposta::class)->executar($this->admin, $this->negocioComItens());
        $p = app(AtualizarProposta::class)->executar($this->admin, $p, ['validade_dias' => 30, 'observacoes' => 'Pagamento em 2x']);
        $this->assertSame(30, $p->validade_dias);
        $this->assertSame('Pagamento em 2x', $p->observacoes);

        $enviada = app(EnviarProposta::class)->executar($this->admin, $p);
        $this->assertSame('PROPOSTA_NAO_EDITAVEL', $this->codigoDe(
            fn () => app(AtualizarProposta::class)->executar($this->admin, $enviada, ['validade_dias' => 5]),
        ));
    }

    public function test_enviar_congela_gera_token_e_registra_timeline(): void
    {
        $negocio = $this->negocioComItens();
        $p = $this->propostaEnviada($negocio);

        $this->assertSame(StatusDaProposta::Enviada, $p->status);
        $this->assertSame(64, strlen((string) $p->token));
        $this->assertSame(hash('sha256', (string) $p->token), $p->token_hash);
        $this->assertTrue($p->validade_em->between(now()->addDays(14), now()->addDays(15)->addMinute()));
        $this->assertSame(1, RegistroDaTimeline::query()->where('tipo', TipoDeRegistro::PropostaEnviada)->count());

        $negocio->itens()->first()->update(['quantidade' => 99]);
        $this->assertSame(2, $p->refresh()->itens->first()->quantidade);
    }

    public function test_cancelar_invalida_o_link(): void
    {
        $p = $this->propostaEnviada();

        $p = app(CancelarProposta::class)->executar($this->admin, $p);
        $this->assertSame(StatusDaProposta::Cancelada, $p->status);
        $this->assertNull($p->token_hash);

        $this->assertSame('PROPOSTA_NAO_CANCELAVEL', $this->codigoDe(fn () => app(CancelarProposta::class)->executar($this->admin, $p)));
    }

    public function test_cancelar_aceita_bloqueia(): void
    {
        $p = $this->propostaEnviada();
        $p->forceFill(['status' => StatusDaProposta::Aceita])->save();

        $this->assertSame('PROPOSTA_NAO_CANCELAVEL', $this->codigoDe(fn () => app(CancelarProposta::class)->executar($this->admin, $p)));
        $this->assertSame(1, Proposta::query()->count());
    }

    public function test_leitura_nao_opera(): void
    {
        $leitor = $this->usuarioDoPapel(Papel::Leitura);

        $this->expectException(AcessoNegadoException::class);
        app(CriarProposta::class)->executar($leitor, $this->negocioComItens());
    }
}

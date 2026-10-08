<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Modules\Billing\Application\Actions\CancelarDowngrade;
use App\Modules\Billing\Application\Actions\TrocarDePlano;
use App\Modules\Billing\Domain\Enums\CicloDeCobranca;
use App\Modules\Billing\Domain\Enums\MetodoDePagamento;
use App\Modules\Billing\Domain\Enums\StatusDaAssinatura;
use App\Modules\Billing\Domain\Enums\StatusDaCobranca;
use App\Modules\Billing\Domain\Enums\TipoDeCobranca;
use App\Modules\Billing\Domain\Exceptions\BillingException;
use App\Modules\Billing\Domain\Models\Assinatura;
use App\Modules\Billing\Domain\Models\Cobranca;
use App\Modules\Billing\Domain\ResultadoDaTroca;
use App\Modules\Billing\Domain\TipoDeTroca;
use App\Modules\Identity\Domain\Enums\Papel;
use App\Modules\Identity\Domain\Models\Usuario;
use App\Modules\Platform\Domain\Enums\Recurso;
use App\Modules\Platform\Domain\Exceptions\PlanoInsuficienteException;
use App\Modules\Platform\Domain\Models\Plano;
use App\Modules\Tenancy\Domain\Enums\SituacaoAssinatura;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\Fixtures\CenarioDeCobranca;
use Tests\TestCase;

class TrocarDePlanoTest extends TestCase
{
    use CenarioDeCobranca;
    use RefreshDatabase;

    private Assinatura $assinatura;

    private Plano $caro;

    private Plano $barato;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-15 12:00:00', 'America/Sao_Paulo'));
        $this->montarCenarioCobranca();
        $this->tenant->forceFill(['situacao' => SituacaoAssinatura::Ativa])->save();
        $this->caro = $this->plano(16000);
        $this->barato = $this->plano(5000);
        $this->assinatura = $this->assinaturaAtiva(MetodoDePagamento::Cartao);
    }

    /** @param array<string, mixed> $campos */
    private function plano(int $mensal, array $campos = [], int $limiteUsuarios = Plano::ILIMITADO): Plano
    {
        $limites = [Recurso::Usuarios->value => $limiteUsuarios];
        foreach ([Recurso::Clientes, Recurso::Produtos, Recurso::Servicos, Recurso::DocumentosMes, Recurso::ApiRequisicoesMin] as $r) {
            $limites[$r->value] = Plano::ILIMITADO;
        }

        return Plano::factory()->comLimites($limites)->create($campos + ['preco_mensal_centavos' => $mensal, 'preco_anual_centavos' => $mensal * 10]);
    }

    private function assinaturaAtiva(MetodoDePagamento $metodo): Assinatura
    {
        $a = Assinatura::factory()->create(['plano_id' => $this->plano->id]);
        $a->forceFill([
            'situacao' => StatusDaAssinatura::Ativa, 'metodo' => $metodo, 'gateway_assinatura_id' => 'pre-1',
            'paga_ate' => '2026-10-30', 'valor_ciclo_centavos' => 10000,
        ])->save();

        return $a;
    }

    private function trocar(string $planoId, ?Usuario $autor = null): ResultadoDaTroca
    {
        return app(TrocarDePlano::class)->executar($autor ?? $this->admin, $planoId);
    }

    private function codigoDoErro(callable $acao): string
    {
        try {
            $acao();
        } catch (BillingException $e) {
            return $e->codigo();
        }
        $this->fail('deveria lançar BillingException');
    }

    public function test_upgrade_cria_a_cobranca_proporcional_sem_mudar_o_plano(): void
    {
        $r = $this->trocar($this->caro->id);

        $this->assertSame(TipoDeTroca::Upgrade, $r->tipo);
        $c = $r->cobranca;
        $this->assertNotNull($c);
        $this->assertSame([TipoDeCobranca::Upgrade, 3000, StatusDaCobranca::Pendente, $this->caro->id], [$c->tipo, $c->valor_centavos, $c->status, $c->plano_destino_id]);
        $this->assertNull($c->metodo);
        $this->assertSame('2026-10-16', $c->vence_em->toDateString());
        $this->assertSame($this->plano->id, $this->tenant->refresh()->plano_id);
        $this->assertSame($this->plano->id, $this->assinatura->refresh()->plano_id);
        $this->assertSame([], $this->gateway->chamadas);
    }

    public function test_segundo_pedido_para_o_mesmo_destino_reaproveita_e_para_outro_substitui(): void
    {
        $primeira = $this->trocar($this->caro->id)->cobranca;
        $this->assertSame($primeira?->id, $this->trocar($this->caro->id)->cobranca?->id);

        $outro = $this->plano(20000);
        $nova = $this->trocar($outro->id)->cobranca;

        $this->assertNotSame($primeira?->id, $nova?->id);
        $this->assertSame(StatusDaCobranca::Cancelada, $primeira?->refresh()->status);
        $this->assertSame(1, Cobranca::query()->where('tipo', TipoDeCobranca::Upgrade->value)->where('status', StatusDaCobranca::Pendente->value)->count());
        $this->assertSame(5000, $nova?->valor_centavos, '(20000 - 10000) x 15 / 30');
    }

    public function test_upgrade_para_plano_com_limite_menor_que_o_uso_e_bloqueado(): void
    {
        $apertado = $this->plano(16000, [], 0);

        try {
            $this->trocar($apertado->id);
            $this->fail('deveria bloquear');
        } catch (PlanoInsuficienteException $e) {
            $this->assertSame('PLANO_EXCEDIDO', $e->codigo());
        }
        $this->assertSame(0, Cobranca::query()->count());
    }

    public function test_downgrade_agenda_para_o_fim_do_periodo_e_atualiza_o_valor_no_cartao(): void
    {
        $r = $this->trocar($this->barato->id);

        $this->assertSame(TipoDeTroca::Downgrade, $r->tipo);
        $this->assertNull($r->cobranca);
        $a = $this->assinatura->refresh();
        $this->assertSame($this->barato->id, $a->plano_proximo_id);
        $this->assertSame('2026-10-30', $a->plano_proximo_em?->toDateString());
        $this->assertSame($this->plano->id, $a->plano_id, 'o plano atual vale até o fim do período');
        $this->assertSame([['atualizarAssinatura', ['pre-1', 5000]]], $this->gateway->chamadas);
    }

    public function test_downgrade_de_boleto_nao_chama_o_gateway(): void
    {
        $this->assinatura->forceFill(['metodo' => MetodoDePagamento::Boleto])->save();

        $this->trocar($this->barato->id);

        $this->assertSame($this->barato->id, $this->assinatura->refresh()->plano_proximo_id);
        $this->assertSame([], $this->gateway->chamadas);
    }

    public function test_downgrade_bloqueado_por_excesso_nao_grava_nem_chama_o_gateway(): void
    {
        $apertado = $this->plano(5000, [], 0);

        try {
            $this->trocar($apertado->id);
            $this->fail('deveria bloquear');
        } catch (PlanoInsuficienteException $e) {
            $this->assertSame('USUARIOS', $e->extras()['excessos'][0]['recurso']);
        }
        $this->assertNull($this->assinatura->refresh()->plano_proximo_id);
        $this->assertSame([], $this->gateway->chamadas);
    }

    public function test_falha_do_gateway_no_downgrade_nao_grava_nada(): void
    {
        $this->gateway->falharEm('atualizarAssinatura', new RuntimeException('caiu'));

        $this->assertSame('BILLING_GATEWAY_FALHOU', $this->codigoDoErro(fn () => $this->trocar($this->barato->id)));
        $this->assertNull($this->assinatura->refresh()->plano_proximo_id);
    }

    public function test_cancelar_o_downgrade_limpa_o_agendamento_e_devolve_o_valor_no_cartao(): void
    {
        $this->trocar($this->barato->id);

        $a = app(CancelarDowngrade::class)->executar($this->admin);

        $this->assertNull($a->plano_proximo_id);
        $this->assertNull($a->plano_proximo_em);
        $this->assertSame(['atualizarAssinatura', ['pre-1', 10000]], $this->gateway->chamadas[1]);
    }

    public function test_cancelar_sem_agendamento_e_recusado(): void
    {
        $this->assertSame('BILLING_SEM_DOWNGRADE', $this->codigoDoErro(fn () => app(CancelarDowngrade::class)->executar($this->admin)));
    }

    public function test_recusas(): void
    {
        $vendedor = Usuario::factory()->for($this->tenant)->create(['papel' => Papel::Vendedor]);
        $this->assertSame('BILLING_SEM_PERMISSAO', $this->codigoDoErro(fn () => $this->trocar($this->caro->id, $vendedor)));

        $this->assertSame('BILLING_TROCA_MESMO_PLANO', $this->codigoDoErro(fn () => $this->trocar($this->plano->id)));

        $igual = $this->plano(10000);
        $this->assertSame('BILLING_TROCA_MESMO_PRECO', $this->codigoDoErro(fn () => $this->trocar($igual->id)));

        $inativo = $this->plano(16000, ['ativo' => false]);
        $invisivel = $this->plano(16000, ['visivel' => false]);
        $this->assertSame('BILLING_PLANO_INDISPONIVEL', $this->codigoDoErro(fn () => $this->trocar($inativo->id)));
        $this->assertSame('BILLING_PLANO_INDISPONIVEL', $this->codigoDoErro(fn () => $this->trocar($invisivel->id)));

        $semAnual = $this->plano(16000, ['preco_anual_centavos' => null]);
        $this->assinatura->forceFill(['ciclo' => CicloDeCobranca::Anual, 'valor_ciclo_centavos' => 100000])->save();
        $this->assertSame('BILLING_CICLO_INDISPONIVEL', $this->codigoDoErro(fn () => $this->trocar($semAnual->id)));
        $this->assertSame(0, Cobranca::query()->count());
    }

    public function test_so_com_assinatura_ativa_em_dia_e_conta_ativa(): void
    {
        $this->tenant->forceFill(['situacao' => SituacaoAssinatura::Inadimplente])->save();
        $this->assertSame('BILLING_TROCA_INDISPONIVEL', $this->codigoDoErro(fn () => $this->trocar($this->caro->id)));

        $this->tenant->forceFill(['situacao' => SituacaoAssinatura::Ativa])->save();
        $this->assinatura->forceFill(['paga_ate' => '2026-10-14'])->save();
        $this->assertSame('BILLING_TROCA_INDISPONIVEL', $this->codigoDoErro(fn () => $this->trocar($this->caro->id)));

        $this->assinatura->forceFill(['paga_ate' => '2026-10-30', 'situacao' => StatusDaAssinatura::Pendente])->save();
        $this->assertSame('BILLING_TROCA_INDISPONIVEL', $this->codigoDoErro(fn () => $this->trocar($this->caro->id)));
    }
}

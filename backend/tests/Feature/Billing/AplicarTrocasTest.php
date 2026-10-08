<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Modules\Billing\Domain\Enums\MetodoDePagamento;
use App\Modules\Billing\Domain\Enums\StatusDaAssinatura;
use App\Modules\Billing\Domain\Gateway\DadosDoBoleto;
use App\Modules\Billing\Domain\Models\Assinatura;
use App\Modules\Platform\Domain\Enums\Recurso;
use App\Modules\Platform\Domain\Models\Plano;
use App\Modules\Tenancy\Domain\Enums\SituacaoAssinatura;
use App\Modules\Tenancy\Domain\Models\Tenant;
use App\Modules\Tenancy\Domain\TenantContext;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\Fixtures\CenarioDeCobranca;
use Tests\TestCase;

class AplicarTrocasTest extends TestCase
{
    use CenarioDeCobranca;
    use RefreshDatabase;

    private Plano $barato;

    private Assinatura $assinatura;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-30 12:00:00', 'America/Sao_Paulo'));
        $this->montarCenarioCobranca();
        $this->tenant->forceFill(['situacao' => SituacaoAssinatura::Ativa])->save();
        $this->barato = $this->planoComLimiteDeUsuarios(5000, Plano::ILIMITADO);
        $this->assinatura = $this->agendar($this->tenant, $this->barato, '2026-10-30', MetodoDePagamento::Cartao);
    }

    private function planoComLimiteDeUsuarios(int $mensal, int $usuarios): Plano
    {
        $limites = [];
        foreach (Recurso::cases() as $r) {
            $limites[$r->value] = Plano::ILIMITADO;
        }
        $limites[Recurso::Usuarios->value] = $usuarios;

        return Plano::factory()->comLimites($limites)->create(['preco_mensal_centavos' => $mensal, 'preco_anual_centavos' => $mensal * 10]);
    }

    private function agendar(Tenant $tenant, Plano $destino, string $em, MetodoDePagamento $metodo): Assinatura
    {
        $contexto = app(TenantContext::class);
        $anterior = $contexto->id();
        $contexto->set($tenant->id);
        $a = Assinatura::factory()->create(['plano_id' => $tenant->plano_id]);
        $a->forceFill([
            'situacao' => StatusDaAssinatura::Ativa, 'metodo' => $metodo, 'gateway_assinatura_id' => 'pre-'.$tenant->id,
            'paga_ate' => $em, 'valor_ciclo_centavos' => 10000, 'plano_proximo_id' => $destino->id, 'plano_proximo_em' => $em,
        ])->save();
        $contexto->set($anterior);

        return $a;
    }

    private function rodar(): void
    {
        $this->artisan('billing:aplicar-trocas')->assertSuccessful();
    }

    /** @return list<array{0: string, 1: list<mixed>}> */
    private function chamadas(string $metodo): array
    {
        return array_values(array_filter($this->gateway->chamadas, fn (array $c): bool => $c[0] === $metodo));
    }

    public function test_aplica_no_dia_troca_os_dois_planos_e_o_valor_do_ciclo(): void
    {
        $this->rodar();

        $a = $this->assinatura->refresh();
        $this->assertSame($this->barato->id, $this->tenant->refresh()->plano_id);
        $this->assertSame([$this->barato->id, 5000, null, null], [$a->plano_id, $a->valor_ciclo_centavos, $a->plano_proximo_id, $a->plano_proximo_em]);
        $this->assertSame([], $this->chamadas('atualizarAssinatura'), 'o cartão já foi atualizado ao agendar');
    }

    public function test_nao_aplica_antes_do_dia_e_e_idempotente(): void
    {
        $this->travelTo(Carbon::parse('2026-10-29 12:00:00', 'America/Sao_Paulo'));
        $this->rodar();
        $this->assertSame($this->plano->id, $this->tenant->refresh()->plano_id);
        $this->assertSame($this->barato->id, $this->assinatura->refresh()->plano_proximo_id);

        $this->travelTo(Carbon::parse('2026-10-30 12:00:00', 'America/Sao_Paulo'));
        $this->rodar();
        $this->rodar();
        $this->assertSame($this->barato->id, $this->tenant->refresh()->plano_id);
    }

    public function test_com_excesso_cancela_o_agendamento_devolve_o_valor_e_avisa(): void
    {
        $apertado = $this->planoComLimiteDeUsuarios(5000, 0);
        $this->assinatura->forceFill(['plano_proximo_id' => $apertado->id])->save();
        Log::spy();

        $this->rodar();

        $a = $this->assinatura->refresh();
        $this->assertSame($this->plano->id, $this->tenant->refresh()->plano_id);
        $this->assertSame([$this->plano->id, 10000, null, null], [$a->plano_id, $a->valor_ciclo_centavos, $a->plano_proximo_id, $a->plano_proximo_em]);
        $this->assertSame([['atualizarAssinatura', ['pre-'.$this->tenant->id, 10000]]], $this->chamadas('atualizarAssinatura'));
        Log::shouldHaveReceived('warning')->once();
    }

    public function test_ignora_assinatura_cancelada_e_conta_que_nao_esta_ativa(): void
    {
        $this->assinatura->forceFill(['situacao' => StatusDaAssinatura::Cancelada])->save();
        $this->rodar();
        $this->assertSame($this->plano->id, $this->tenant->refresh()->plano_id);

        $this->assinatura->forceFill(['situacao' => StatusDaAssinatura::Ativa])->save();
        $this->tenant->forceFill(['situacao' => SituacaoAssinatura::Suspensa])->save();
        $this->rodar();
        $this->assertSame($this->plano->id, $this->tenant->refresh()->plano_id);
    }

    public function test_so_troca_o_plano_de_quem_chegou_ao_dia_e_restaura_o_contexto(): void
    {
        $outro = Tenant::factory()->for($this->plano)->situacao(SituacaoAssinatura::Ativa)->create(['cnpj' => '52998224000172']);
        $this->agendar($outro, $this->barato, '2026-11-20', MetodoDePagamento::Boleto);
        $contexto = app(TenantContext::class);
        $antes = $contexto->id();

        $this->rodar();

        $this->assertSame($this->barato->id, $this->tenant->refresh()->plano_id);
        $this->assertSame($this->plano->id, $outro->refresh()->plano_id);
        $this->assertSame($antes, $contexto->id());
    }

    public function test_o_boleto_do_proximo_ciclo_sai_no_preco_do_plano_agendado(): void
    {
        $this->assinatura->forceFill(['metodo' => MetodoDePagamento::Boleto, 'paga_ate' => '2026-11-02', 'plano_proximo_em' => '2026-11-02'])->save();

        $this->artisan('billing:gerar-boletos')->assertSuccessful();

        $pedido = $this->chamadas('criarBoleto')[0][1][0];
        $this->assertInstanceOf(DadosDoBoleto::class, $pedido);
        $this->assertSame(5000, $pedido->valorCentavos);
        $this->assertStringContainsString($this->barato->nome, $pedido->descricao);
    }

    public function test_o_boleto_sem_agendamento_continua_no_preco_do_plano_atual(): void
    {
        $this->assinatura->forceFill(['metodo' => MetodoDePagamento::Boleto, 'paga_ate' => '2026-11-02', 'plano_proximo_id' => null, 'plano_proximo_em' => null])->save();

        $this->artisan('billing:gerar-boletos')->assertSuccessful();

        $this->assertSame(10000, $this->chamadas('criarBoleto')[0][1][0]->valorCentavos);
    }
}

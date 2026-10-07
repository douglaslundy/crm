<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Modules\Billing\Application\Actions\CancelarAssinatura;
use App\Modules\Billing\Application\Actions\IniciarAssinatura;
use App\Modules\Billing\Application\Actions\ResultadoDoCheckout;
use App\Modules\Billing\Domain\Enums\CicloDeCobranca;
use App\Modules\Billing\Domain\Enums\MetodoDePagamento;
use App\Modules\Billing\Domain\Enums\StatusDaAssinatura;
use App\Modules\Billing\Domain\Enums\StatusDaCobranca;
use App\Modules\Billing\Domain\Exceptions\BillingException;
use App\Modules\Billing\Domain\Gateway\ResultadoDePagamento;
use App\Modules\Billing\Domain\Gateway\StatusDoPagamento;
use App\Modules\Billing\Domain\Models\Assinatura;
use App\Modules\Billing\Domain\Models\Cobranca;
use App\Modules\Identity\Domain\Enums\Papel;
use App\Modules\Identity\Domain\Models\Usuario;
use App\Modules\Platform\Domain\Models\Plano;
use App\Modules\Tenancy\Domain\Enums\SituacaoAssinatura;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\Fixtures\CenarioDeCobranca;
use Tests\TestCase;

class IniciarAssinaturaTest extends TestCase
{
    use CenarioDeCobranca;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->montarCenarioCobranca();
    }

    private function iniciar(CicloDeCobranca $ciclo, MetodoDePagamento $metodo, ?string $token = null, ?Usuario $autor = null, ?string $planoId = null): ResultadoDoCheckout
    {
        return app(IniciarAssinatura::class)->executar($autor ?? $this->admin, $planoId ?? $this->plano->id, $ciclo, $metodo, $token);
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

    public function test_boleto_cria_assinatura_pendente_e_cobranca_com_boleto(): void
    {
        $this->gateway->enfileirarBoleto(new ResultadoDePagamento('pg-1', StatusDoPagamento::Pendente, null, 10000, boletoUrl: 'https://mp.test/b/1', linhaDigitavel: '1234.5678'));

        $r = $this->iniciar(CicloDeCobranca::Mensal, MetodoDePagamento::Boleto);

        $this->assertSame(10000, $r->cobranca->valor_centavos);
        $this->assertSame('https://mp.test/b/1', $r->cobranca->boleto_url);
        $this->assertSame('1234.5678', $r->cobranca->boleto_linha_digitavel);
        $this->assertSame('pg-1', $r->cobranca->gateway_pagamento_id);
        $this->assertSame(today((string) config('app.fuso_negocio'))->addDays(3)->toDateString(), $r->cobranca->vence_em->toDateString());
        $this->assertSame(StatusDaAssinatura::Pendente, $r->assinatura->situacao);
        $this->assertSame(SituacaoAssinatura::Teste, $this->tenant->refresh()->situacao, 'só o pagamento ativa');
    }

    public function test_cartao_cria_o_preapproval_com_valor_do_servidor_e_idempotencia(): void
    {
        $r = $this->iniciar(CicloDeCobranca::Anual, MetodoDePagamento::Cartao, 'tok_123');

        [$metodo, $args] = $this->gateway->chamadas[0];
        $this->assertSame('criarAssinaturaDeCartao', $metodo);
        $this->assertSame(100000, $args[0]->valorCentavos);
        $this->assertSame(12, $args[0]->meses);
        $this->assertSame('tok_123', $args[0]->tokenDoCartao);
        $this->assertSame($r->cobranca->id, $args[0]->idempotencia);
        $this->assertSame($this->admin->email, $args[0]->emailDoPagador);
        $this->assertNotNull($r->assinatura->gateway_assinatura_id);
        $this->assertSame(100000, $r->cobranca->valor_centavos);
    }

    public function test_cartao_sem_token_e_recusado_sem_criar_nada(): void
    {
        $codigo = $this->codigoDoErro(fn () => $this->iniciar(CicloDeCobranca::Mensal, MetodoDePagamento::Cartao, null));

        $this->assertSame('BILLING_CARTAO_SEM_TOKEN', $codigo);
        $this->assertSame(0, Assinatura::query()->count());
    }

    public function test_plano_inativo_invisivel_ou_sem_preco_do_ciclo_e_recusado(): void
    {
        $inativo = Plano::factory()->create(['ativo' => false, 'preco_mensal_centavos' => 5000]);
        $invisivel = Plano::factory()->create(['visivel' => false, 'preco_mensal_centavos' => 5000]);
        $semAnual = Plano::factory()->create(['preco_mensal_centavos' => 5000, 'preco_anual_centavos' => null]);

        $this->assertSame('BILLING_PLANO_INDISPONIVEL', $this->codigoDoErro(fn () => $this->iniciar(CicloDeCobranca::Mensal, MetodoDePagamento::Boleto, null, null, $inativo->id)));
        $this->assertSame('BILLING_PLANO_INDISPONIVEL', $this->codigoDoErro(fn () => $this->iniciar(CicloDeCobranca::Mensal, MetodoDePagamento::Boleto, null, null, $invisivel->id)));
        $this->assertSame('BILLING_CICLO_INDISPONIVEL', $this->codigoDoErro(fn () => $this->iniciar(CicloDeCobranca::Anual, MetodoDePagamento::Boleto, null, null, $semAnual->id)));
        $this->assertSame(0, Assinatura::query()->count());
    }

    public function test_so_proprietario_e_admin_assinam(): void
    {
        foreach ([Papel::Fiscal, Papel::Vendedor, Papel::Leitura] as $papel) {
            $usuario = Usuario::factory()->for($this->tenant)->create(['papel' => $papel]);

            $this->assertSame('BILLING_SEM_PERMISSAO', $this->codigoDoErro(fn () => $this->iniciar(CicloDeCobranca::Mensal, MetodoDePagamento::Boleto, null, $usuario)), $papel->value);
        }
        $proprietario = Usuario::factory()->for($this->tenant)->create(['papel' => Papel::Proprietario]);
        $this->iniciar(CicloDeCobranca::Mensal, MetodoDePagamento::Boleto, null, $proprietario);
        $this->assertSame(1, Assinatura::query()->count());
    }

    public function test_segunda_assinatura_ativa_ou_pendente_com_cobranca_aberta_e_recusada(): void
    {
        $this->iniciar(CicloDeCobranca::Mensal, MetodoDePagamento::Boleto);

        $this->assertSame('BILLING_ASSINATURA_ATIVA', $this->codigoDoErro(fn () => $this->iniciar(CicloDeCobranca::Mensal, MetodoDePagamento::Boleto)));
        $this->assertSame(1, Assinatura::query()->count());
    }

    public function test_falha_do_gateway_nao_deixa_assinatura_pendente_orfa(): void
    {
        $this->gateway->falharEm('criarBoleto', new RuntimeException('mp fora'));

        $this->assertSame('BILLING_GATEWAY_FALHOU', $this->codigoDoErro(fn () => $this->iniciar(CicloDeCobranca::Mensal, MetodoDePagamento::Boleto)));

        $this->assertSame(0, Assinatura::query()->count());
        $this->assertSame(0, Cobranca::query()->count());
    }

    public function test_assinatura_pendente_antiga_sem_cobranca_aberta_pode_ser_refeita(): void
    {
        $antiga = $this->cobrancaPendente(['status' => StatusDaCobranca::Cancelada]);

        $r = $this->iniciar(CicloDeCobranca::Mensal, MetodoDePagamento::Boleto);

        $this->assertSame(StatusDaAssinatura::Cancelada, $antiga->assinatura->refresh()->situacao);
        $this->assertSame(StatusDaAssinatura::Pendente, $r->assinatura->situacao);
    }

    /** Assinatura ATIVA de boleto, período pago vencido, com uma cobrança vencida; tenant inadimplente. */
    private function assinaturaAtrasada(SituacaoAssinatura $situacao = SituacaoAssinatura::Inadimplente): Assinatura
    {
        $c = $this->cobrancaPendente(['status' => StatusDaCobranca::Vencida, 'vence_em' => today((string) config('app.fuso_negocio'))->subDays(3)->toDateString()]);
        $c->assinatura->forceFill(['situacao' => StatusDaAssinatura::Ativa, 'paga_ate' => today((string) config('app.fuso_negocio'))->subDays(3)->toDateString()])->save();
        $this->tenant->forceFill(['situacao' => $situacao])->save();
        $this->admin->unsetRelation('tenant');

        return $c->assinatura;
    }

    public function test_quem_esta_atrasado_regulariza_com_um_novo_boleto_na_mesma_assinatura(): void
    {
        $a = $this->assinaturaAtrasada();

        $r = $this->iniciar(CicloDeCobranca::Mensal, MetodoDePagamento::Boleto);

        $this->assertSame($a->id, $r->assinatura->id);
        $this->assertSame(1, Assinatura::query()->count());
        $this->assertSame(StatusDaCobranca::Pendente, $r->cobranca->status);
        $this->assertSame(10000, $r->cobranca->valor_centavos);
        $this->assertSame(today((string) config('app.fuso_negocio'))->addDays(3)->toDateString(), $r->cobranca->vence_em->toDateString(), 'vencimento no futuro: boleto vencido não se paga');
        $this->assertNotNull($r->cobranca->boleto_url);
    }

    public function test_suspenso_tambem_regulariza_mas_com_boleto_em_aberto_recusa(): void
    {
        $this->assinaturaAtrasada(SituacaoAssinatura::Suspensa);
        $this->iniciar(CicloDeCobranca::Mensal, MetodoDePagamento::Boleto);

        $this->assertSame('BILLING_ASSINATURA_ATIVA', $this->codigoDoErro(fn () => $this->iniciar(CicloDeCobranca::Mensal, MetodoDePagamento::Boleto)));
        $this->assertSame(2, Cobranca::query()->count());
    }

    public function test_assinatura_ativa_em_dia_continua_recusando_nova_assinatura(): void
    {
        $this->assinaturaAtrasada(SituacaoAssinatura::Ativa);

        $this->assertSame('BILLING_ASSINATURA_ATIVA', $this->codigoDoErro(fn () => $this->iniciar(CicloDeCobranca::Mensal, MetodoDePagamento::Boleto)));
    }

    public function test_regularizacao_so_por_boleto(): void
    {
        $this->assinaturaAtrasada();

        $this->assertSame('BILLING_ASSINATURA_ATIVA', $this->codigoDoErro(fn () => $this->iniciar(CicloDeCobranca::Mensal, MetodoDePagamento::Cartao, 'tok')));
    }

    public function test_trocar_a_assinatura_pendente_de_cartao_cancela_a_antiga_no_gateway(): void
    {
        $c = $this->cobrancaPendente();   // gateway_assinatura_id = pre-1
        $c->assinatura->forceFill(['metodo' => MetodoDePagamento::Cartao])->save();
        $c->forceFill(['metodo' => MetodoDePagamento::Cartao])->save();

        $this->iniciar(CicloDeCobranca::Mensal, MetodoDePagamento::Boleto);

        $this->assertContains(['cancelarAssinatura', ['pre-1']], $this->gateway->chamadas);
        $this->assertSame(StatusDaAssinatura::Cancelada, $c->assinatura->refresh()->situacao);
    }

    public function test_boleto_que_acabou_de_vencer_ainda_pode_ser_pago_e_segura_a_troca(): void
    {
        $hoje = today((string) config('app.fuso_negocio'));
        $c = $this->cobrancaPendente(['status' => StatusDaCobranca::Vencida, 'vence_em' => $hoje->copy()->subDays(2)->toDateString()]);

        $this->assertSame('BILLING_ASSINATURA_ATIVA', $this->codigoDoErro(fn () => $this->iniciar(CicloDeCobranca::Mensal, MetodoDePagamento::Boleto)));

        $c->forceFill(['vence_em' => $hoje->copy()->subDays(6)->toDateString()])->save();   // passou a tolerância de compensação
        $this->iniciar(CicloDeCobranca::Mensal, MetodoDePagamento::Boleto);
        $this->assertSame(StatusDaAssinatura::Cancelada, $c->assinatura->refresh()->situacao);
    }

    public function test_cartao_nao_autorizado_cancela_o_preapproval_criado_no_gateway(): void
    {
        $this->gateway->autorizar = false;

        $this->assertSame('BILLING_CARTAO_RECUSADO', $this->codigoDoErro(fn () => $this->iniciar(CicloDeCobranca::Mensal, MetodoDePagamento::Cartao, 'tok')));

        $this->assertSame(0, Assinatura::query()->count());
        $this->assertContains(['cancelarAssinatura', ['pre-1']], $this->gateway->chamadas);
    }

    public function test_o_banco_nao_aceita_duas_assinaturas_nao_canceladas_no_mesmo_tenant(): void
    {
        $this->cobrancaPendente();

        $this->expectException(QueryException::class);

        Assinatura::factory()->create(['plano_id' => $this->plano->id]);
    }

    public function test_cancelar_cancela_o_preapproval_a_assinatura_e_as_cobrancas_pendentes_e_mantem_o_acesso(): void
    {
        $c = $this->cobrancaPendente();
        $c->assinatura->forceFill(['situacao' => StatusDaAssinatura::Ativa])->save();
        $this->tenant->forceFill(['situacao' => SituacaoAssinatura::Ativa])->save();

        app(CancelarAssinatura::class)->executar($this->admin);

        $this->assertSame(['cancelarAssinatura', ['pre-1']], $this->gateway->chamadas[0]);
        $this->assertSame(StatusDaAssinatura::Cancelada, $c->assinatura->refresh()->situacao);
        $this->assertNotNull($c->assinatura->cancelada_em);
        $this->assertSame(StatusDaCobranca::Cancelada, $c->refresh()->status);
        $this->assertSame(SituacaoAssinatura::Ativa, $this->tenant->refresh()->situacao);
    }

    public function test_cancelar_sem_assinatura_da_404(): void
    {
        try {
            app(CancelarAssinatura::class)->executar($this->admin);
            $this->fail('deveria lançar');
        } catch (BillingException $e) {
            $this->assertSame('BILLING_SEM_ASSINATURA', $e->codigo());
            $this->assertSame(404, $e->status());
        }
    }

    public function test_so_proprietario_e_admin_cancelam(): void
    {
        $this->cobrancaPendente();
        $vendedor = Usuario::factory()->for($this->tenant)->create(['papel' => Papel::Vendedor]);

        $this->assertSame('BILLING_SEM_PERMISSAO', $this->codigoDoErro(fn () => app(CancelarAssinatura::class)->executar($vendedor)));
    }
}

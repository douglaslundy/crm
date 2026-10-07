<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Modules\Billing\Application\Actions\PagarCobranca;
use App\Modules\Billing\Domain\Enums\MetodoDePagamento;
use App\Modules\Billing\Domain\Enums\StatusDaCobranca;
use App\Modules\Billing\Domain\Enums\TipoDeCobranca;
use App\Modules\Billing\Domain\Exceptions\BillingException;
use App\Modules\Billing\Domain\Gateway\DadosDoPagamentoDeCartao;
use App\Modules\Billing\Domain\Gateway\DadosDoPix;
use App\Modules\Billing\Domain\Gateway\ResultadoDePagamento;
use App\Modules\Billing\Domain\Gateway\StatusDoPagamento;
use App\Modules\Billing\Domain\Models\Assinatura;
use App\Modules\Billing\Domain\Models\Cobranca;
use App\Modules\Identity\Domain\Enums\Papel;
use App\Modules\Identity\Domain\Models\Usuario;
use App\Modules\Tenancy\Domain\Enums\SituacaoAssinatura;
use App\Modules\Tenancy\Domain\Models\Tenant;
use App\Modules\Tenancy\Domain\TenantContext;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\Fixtures\CenarioDeCobranca;
use Tests\TestCase;

class PagarCobrancaTest extends TestCase
{
    use CenarioDeCobranca;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-01 12:00:00', 'America/Sao_Paulo'));
        $this->montarCenarioCobranca();
    }

    private function pagar(Cobranca $c, MetodoDePagamento $metodo = MetodoDePagamento::Pix, ?string $token = null, ?Usuario $autor = null): Cobranca
    {
        return app(PagarCobranca::class)->executar($autor ?? $this->admin, $c->id, $metodo, $token);
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

    /** @return list<DadosDoPix> */
    private function pedidosDePix(): array
    {
        return array_values(array_map(fn (array $c): DadosDoPix => $c[1][0], array_filter($this->gateway->chamadas, fn (array $c): bool => $c[0] === 'criarPix')));
    }

    public function test_pix_novo_grava_o_qr_code_e_pede_o_valor_da_cobranca(): void
    {
        $c = $this->excedentePendente();

        $r = $this->pagar($c);

        $this->assertSame(MetodoDePagamento::Pix, $r->metodo);
        $this->assertSame('000201PIXFAKE', $r->pix_qr_code);
        $this->assertSame('QVJDRA==', $r->pix_qr_code_base64);
        $this->assertNotNull($r->gateway_pagamento_id);
        $this->assertSame(StatusDaCobranca::Pendente, $r->status);
        [$pedido] = $this->pedidosDePix();
        $this->assertSame(300, $pedido->valorCentavos);
        $this->assertSame($c->id, $pedido->cobrancaId);
        $this->assertSame($c->id, $pedido->idempotencia);
        $this->assertSame($this->admin->email, $pedido->emailDoPagador);
        $this->assertSame('11444777000161', $pedido->documento);
        $this->assertSame(now()->addMinutes(30)->timestamp, $pedido->expiraEm->timestamp);
        $this->assertSame($pedido->expiraEm->timestamp, $r->pix_expira_em?->timestamp);
    }

    public function test_pix_ainda_valido_e_devolvido_sem_chamar_o_gateway_de_novo(): void
    {
        $c = $this->excedentePendente();
        $primeiro = $this->pagar($c);

        $segundo = $this->pagar($c);

        $this->assertCount(1, $this->pedidosDePix());
        $this->assertSame($primeiro->gateway_pagamento_id, $segundo->gateway_pagamento_id);
        $this->assertSame($primeiro->pix_qr_code, $segundo->pix_qr_code);
    }

    public function test_pix_expirado_gera_outro_com_chave_nova(): void
    {
        $c = $this->excedentePendente();
        $primeiro = $this->pagar($c);
        $this->travelTo(now()->addMinutes(31));

        $segundo = $this->pagar($c);

        $pedidos = $this->pedidosDePix();
        $this->assertCount(2, $pedidos);
        $this->assertSame($c->id, $pedidos[0]->idempotencia);
        $this->assertNotSame($c->id, $pedidos[1]->idempotencia);
        $this->assertNotSame($primeiro->gateway_pagamento_id, $segundo->gateway_pagamento_id);
        $this->assertGreaterThan($primeiro->pix_expira_em?->timestamp, $segundo->pix_expira_em?->timestamp);
    }

    public function test_cartao_aprovado_concilia_na_hora(): void
    {
        $c = $this->excedentePendente();
        $this->tenant->forceFill(['situacao' => SituacaoAssinatura::Ativa])->save();
        $this->gateway->enfileirarCartao(new ResultadoDePagamento('car-1', StatusDoPagamento::Aprovado, $c->id, 300));
        $this->gateway->enfileirarPagamento(new ResultadoDePagamento('car-1', StatusDoPagamento::Aprovado, $c->id, 300));

        $r = $this->pagar($c, MetodoDePagamento::Cartao, 'tok-1');

        $this->assertSame(StatusDaCobranca::Paga, $r->status);
        $this->assertSame(MetodoDePagamento::Cartao, $r->metodo);
        $this->assertSame('car-1', $r->gateway_pagamento_id);
        $chamada = collect($this->gateway->chamadas)->first(fn (array $x): bool => $x[0] === 'criarPagamentoDeCartao');
        $this->assertInstanceOf(DadosDoPagamentoDeCartao::class, $chamada[1][0]);
        $this->assertSame(['tok-1', 300, $c->id], [$chamada[1][0]->tokenDoCartao, $chamada[1][0]->valorCentavos, $chamada[1][0]->idempotencia]);
    }

    public function test_cartao_recusado_nao_grava_nada(): void
    {
        $c = $this->excedentePendente();
        $this->gateway->enfileirarCartao(new ResultadoDePagamento('car-1', StatusDoPagamento::Recusado, $c->id, 300));

        $this->assertSame('BILLING_CARTAO_RECUSADO', $this->codigoDoErro(fn () => $this->pagar($c, MetodoDePagamento::Cartao, 'tok-1')));

        $c->refresh();
        $this->assertNull($c->gateway_pagamento_id);
        $this->assertNull($c->metodo);
        $this->assertSame(StatusDaCobranca::Pendente, $c->status);
    }

    public function test_cartao_sem_token_metodo_boleto_e_tipo_ou_status_invalidos_sao_recusados(): void
    {
        $c = $this->excedentePendente();

        $this->assertSame('BILLING_CARTAO_SEM_TOKEN', $this->codigoDoErro(fn () => $this->pagar($c, MetodoDePagamento::Cartao, ' ')));
        $this->assertSame('BILLING_METODO_INVALIDO', $this->codigoDoErro(fn () => $this->pagar($c, MetodoDePagamento::Boleto)));

        $mensalidade = Cobranca::factory()->for($c->assinatura)->create();
        $this->assertSame(TipoDeCobranca::Assinatura, $mensalidade->tipo);
        $this->assertSame('BILLING_COBRANCA_NAO_PAGAVEL', $this->codigoDoErro(fn () => $this->pagar($mensalidade)));

        foreach ([StatusDaCobranca::Paga, StatusDaCobranca::Cancelada] as $status) {
            $c->forceFill(['status' => $status])->save();
            $this->assertSame('BILLING_COBRANCA_NAO_PAGAVEL', $this->codigoDoErro(fn () => $this->pagar($c)));
        }
        $this->assertSame([], $this->gateway->chamadas);
    }

    public function test_cobranca_vencida_tambem_paga(): void
    {
        $c = $this->excedentePendente(['status' => StatusDaCobranca::Vencida]);

        $this->assertSame(MetodoDePagamento::Pix, $this->pagar($c)->metodo);
    }

    public function test_so_proprietario_e_admin_pagam(): void
    {
        $c = $this->excedentePendente();
        $vendedor = Usuario::factory()->for($this->tenant)->create(['papel' => Papel::Vendedor]);

        $this->assertSame('BILLING_SEM_PERMISSAO', $this->codigoDoErro(fn () => $this->pagar($c, MetodoDePagamento::Pix, null, $vendedor)));
        $this->assertSame([], $this->gateway->chamadas);
    }

    public function test_falha_do_gateway_vira_billing_gateway_falhou_sem_gravar(): void
    {
        $c = $this->excedentePendente();
        $this->gateway->falharEm('criarPix', new RuntimeException('caiu'));

        $this->assertSame('BILLING_GATEWAY_FALHOU', $this->codigoDoErro(fn () => $this->pagar($c)));

        $c->refresh();
        $this->assertNull($c->gateway_pagamento_id);
        $this->assertNull($c->pix_qr_code);
        $this->assertNull($c->metodo);
    }

    public function test_cobranca_de_outro_tenant_nao_existe(): void
    {
        $outro = Tenant::factory()->create();
        app(TenantContext::class)->set($outro->id);
        $assinatura = Assinatura::factory()->create();
        $alheia = $this->excedentePendente([], $assinatura);
        app(TenantContext::class)->set($this->tenant->id);

        $this->expectException(ModelNotFoundException::class);
        $this->pagar($alheia);
    }
}

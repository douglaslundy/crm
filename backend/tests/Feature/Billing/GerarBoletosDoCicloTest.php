<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Modules\Billing\Domain\Enums\MetodoDePagamento;
use App\Modules\Billing\Domain\Enums\StatusDaAssinatura;
use App\Modules\Billing\Domain\Enums\StatusDaCobranca;
use App\Modules\Billing\Domain\Models\Assinatura;
use App\Modules\Billing\Domain\Models\Cobranca;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\Fixtures\CenarioDeCobranca;
use Tests\TestCase;

class GerarBoletosDoCicloTest extends TestCase
{
    use CenarioDeCobranca;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-07 12:00:00', 'America/Sao_Paulo'));
        $this->montarCenarioCobranca();
    }

    private function assinatura(string $pagaAte, MetodoDePagamento $metodo = MetodoDePagamento::Boleto, StatusDaAssinatura $situacao = StatusDaAssinatura::Ativa): Assinatura
    {
        $a = Assinatura::factory()->create(['plano_id' => $this->plano->id]);
        $a->forceFill(['situacao' => $situacao, 'metodo' => $metodo, 'paga_ate' => $pagaAte])->save();

        return $a;
    }

    private function rodar(): void
    {
        $this->artisan('billing:gerar-boletos')->assertSuccessful();
    }

    public function test_gera_o_boleto_do_proximo_ciclo_com_cinco_dias_de_antecedencia(): void
    {
        $a = $this->assinatura('2026-10-12');   // hoje + 5

        $this->rodar();

        $c = Cobranca::query()->where('assinatura_id', $a->id)->firstOrFail();
        $this->assertSame(StatusDaCobranca::Pendente, $c->status);
        $this->assertSame('2026-10-12', $c->vence_em->toDateString());
        $this->assertSame(10000, $c->valor_centavos);
        $this->assertNotNull($c->boleto_url);
        $this->assertSame($c->id, $this->gateway->chamadas[0][1][0]->idempotencia);
    }

    public function test_nao_gera_antes_da_antecedencia(): void
    {
        $this->assinatura('2026-10-13');   // hoje + 6

        $this->rodar();

        $this->assertSame(0, Cobranca::query()->count());
    }

    public function test_rodar_duas_vezes_nao_duplica(): void
    {
        $this->assinatura('2026-10-10');

        $this->rodar();
        $this->rodar();

        $this->assertSame(1, Cobranca::query()->count());
    }

    public function test_ignora_cartao_e_assinatura_cancelada_ou_pendente(): void
    {
        $this->assinatura('2026-10-10', MetodoDePagamento::Cartao);
        $this->assinatura('2026-10-10', MetodoDePagamento::Boleto, StatusDaAssinatura::Cancelada);
        $this->assinatura('2026-10-10', MetodoDePagamento::Boleto, StatusDaAssinatura::Pendente);

        $this->rodar();

        $this->assertSame(0, Cobranca::query()->count());
        $this->assertSame([], $this->gateway->chamadas);
    }

    public function test_falha_do_gateway_nao_deixa_cobranca_e_nao_impede_as_outras(): void
    {
        $this->gateway->falharEm('criarBoleto', new RuntimeException('mp fora'));
        $a = $this->assinatura('2026-10-10');

        $this->rodar();

        $this->assertSame(0, Cobranca::query()->where('assinatura_id', $a->id)->count());
    }
}

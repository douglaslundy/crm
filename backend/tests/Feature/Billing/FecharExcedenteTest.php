<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Modules\Billing\Application\Actions\FecharExcedenteDoMes;
use App\Modules\Billing\Domain\Enums\StatusDaAssinatura;
use App\Modules\Billing\Domain\Enums\StatusDaCobranca;
use App\Modules\Billing\Domain\Enums\TipoDeCobranca;
use App\Modules\Billing\Domain\Models\Assinatura;
use App\Modules\Billing\Domain\Models\Cobranca;
use App\Modules\Customers\Domain\Models\Cliente;
use App\Modules\Fiscal\Domain\Enums\StatusNota;
use App\Modules\Fiscal\Domain\Models\Emitente;
use App\Modules\Fiscal\Domain\Models\Nota;
use App\Modules\Platform\Domain\Enums\PoliticaExcedente;
use App\Modules\Platform\Domain\Enums\Recurso;
use App\Modules\Platform\Domain\Models\Plano;
use App\Modules\Tenancy\Domain\Enums\SituacaoAssinatura;
use App\Modules\Tenancy\Domain\TenantContext;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\Fixtures\CenarioDeCobranca;
use Tests\TestCase;

class FecharExcedenteTest extends TestCase
{
    use CenarioDeCobranca;
    use RefreshDatabase;

    private Assinatura $assinatura;

    private Emitente $emitente;

    private Cliente $cliente;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(CarbonImmutable::parse('2026-11-01 12:00', 'America/Sao_Paulo'));
        $this->montarCenarioCobranca();
        $this->usarPlano(2, PoliticaExcedente::Cobrar, 100);
        $this->assinatura = Assinatura::factory()->create(['plano_id' => $this->plano->id]);
        $this->assinatura->forceFill(['situacao' => StatusDaAssinatura::Ativa, 'gateway_assinatura_id' => 'pre-1', 'paga_ate' => '2026-11-20'])->save();
        $this->emitente = Emitente::factory()->completo()->create();
        $this->cliente = Cliente::factory()->comCpfValido()->create();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function usarPlano(int $limite, PoliticaExcedente $politica, ?int $preco): void
    {
        $this->plano = Plano::factory()->comLimites([Recurso::DocumentosMes->value => $limite])
            ->create(['preco_mensal_centavos' => 10000, 'politica_excedente' => $politica, 'preco_documento_excedente_centavos' => $preco]);
        $this->tenant->forceFill(['plano_id' => $this->plano->id])->save();
        $this->tenant->unsetRelation('plano');
        if (isset($this->assinatura)) {
            $this->assinatura->forceFill(['plano_id' => $this->plano->id])->save();
        }
    }

    private function notas(int $quantidade, string $emitidoEm, string $ambiente = 'PRODUCAO'): void
    {
        for ($i = 0; $i < $quantidade; $i++) {
            $nota = Nota::factory()->create(['emitente_id' => $this->emitente->id, 'cliente_id' => $this->cliente->id]);
            $nota->forceFill(['status' => StatusNota::Autorizada, 'ambiente' => $ambiente, 'emitido_em' => $emitidoEm])->save();
        }
    }

    private function fechar(): int
    {
        return app(FecharExcedenteDoMes::class)->executar(CarbonImmutable::parse('2026-10-01', 'America/Sao_Paulo'));
    }

    private function excedentes(): int
    {
        return Cobranca::query()->where('tipo', TipoDeCobranca::Excedente->value)->count();
    }

    public function test_cria_uma_cobranca_de_excedente_com_o_valor_do_mes(): void
    {
        $this->notas(5, '2026-10-15 15:00:00');

        $this->assertSame(1, $this->fechar());

        $c = Cobranca::query()->where('tipo', TipoDeCobranca::Excedente->value)->firstOrFail();
        $this->assertSame([300, 3, 100, '2026-10-01', '2026-11-11'], [$c->valor_centavos, $c->documentos_excedentes, $c->valor_unitario_centavos, $c->mes_referencia?->toDateString(), $c->vence_em->toDateString()]);
        $this->assertSame(StatusDaCobranca::Pendente, $c->status);
        $this->assertNull($c->metodo);
        $this->assertSame($this->assinatura->id, $c->assinatura_id);
    }

    public function test_rodar_de_novo_nao_duplica(): void
    {
        $this->notas(5, '2026-10-15 15:00:00');

        $this->assertSame(1, $this->fechar());
        $this->assertSame(0, $this->fechar());
        $this->assertSame(1, $this->excedentes());
    }

    public function test_o_fim_do_mes_e_no_fuso_do_negocio(): void
    {
        $this->notas(2, '2026-10-15 12:00:00');
        $this->notas(1, '2026-11-01 02:59:59');  // 31/10 23:59:59 em Sao Paulo: ainda é outubro
        $this->notas(1, '2026-11-01 03:00:00');  // 01/11 00:00:00 em Sao Paulo: novembro

        $this->assertSame(1, $this->fechar());
        $this->assertSame(1, Cobranca::query()->where('tipo', TipoDeCobranca::Excedente->value)->value('documentos_excedentes'));
    }

    public function test_o_inicio_do_mes_e_no_fuso_do_negocio_e_homologacao_nao_conta(): void
    {
        $this->notas(1, '2026-10-01 02:59:59');  // 30/09 23:59:59 em Sao Paulo: setembro
        $this->notas(2, '2026-10-15 12:00:00');
        $this->notas(3, '2026-10-16 12:00:00', 'HOMOLOGACAO');

        $this->assertSame(0, $this->fechar(), 'outubro tem 2 documentos de produção e o limite é 2');
    }

    public function test_dentro_do_limite_ou_ilimitado_nao_gera(): void
    {
        $this->notas(2, '2026-10-15 15:00:00');
        $this->assertSame(0, $this->fechar());

        $this->usarPlano(Plano::ILIMITADO, PoliticaExcedente::Cobrar, 100);
        $this->notas(50, '2026-10-16 15:00:00');
        $this->assertSame(0, $this->fechar());
        $this->assertSame(0, $this->excedentes());
    }

    public function test_plano_bloquear_ou_sem_preco_nao_gera(): void
    {
        $this->notas(5, '2026-10-15 15:00:00');

        $this->usarPlano(2, PoliticaExcedente::Bloquear, 100);
        $this->assertSame(0, $this->fechar());

        $this->usarPlano(2, PoliticaExcedente::Cobrar, null);
        $this->assertSame(0, $this->fechar());
        $this->assertSame(0, $this->excedentes());
    }

    public function test_assinatura_que_nao_esta_ativa_nao_gera(): void
    {
        $this->notas(5, '2026-10-15 15:00:00');

        foreach ([StatusDaAssinatura::Pendente, StatusDaAssinatura::Cancelada] as $situacao) {
            $this->assinatura->forceFill(['situacao' => $situacao])->save();
            $this->assertSame(0, $this->fechar());
        }
        $this->assertSame(0, $this->excedentes());
    }

    public function test_vale_para_conta_suspensa_ou_inadimplente_com_assinatura_ativa(): void
    {
        $this->notas(5, '2026-10-15 15:00:00');
        $this->tenant->forceFill(['situacao' => SituacaoAssinatura::Suspensa])->save();

        $this->assertSame(1, $this->fechar());
    }

    public function test_comando_usa_o_mes_anterior_por_padrao_e_aceita_mes(): void
    {
        $this->notas(5, '2026-10-15 15:00:00');

        $this->artisan('billing:fechar-excedente')->assertSuccessful();
        $this->assertSame(1, $this->excedentes());

        $this->artisan('billing:fechar-excedente --mes=2026-09')->assertSuccessful();
        $this->assertSame(1, $this->excedentes());
    }

    public function test_comando_recusa_mes_invalido(): void
    {
        $this->artisan('billing:fechar-excedente --mes=2026-13')->assertFailed();
        $this->artisan('billing:fechar-excedente --mes=outubro')->assertFailed();
    }

    public function test_o_contexto_do_tenant_e_restaurado(): void
    {
        $this->notas(5, '2026-10-15 15:00:00');
        $contexto = app(TenantContext::class);
        $antes = $contexto->id();

        $this->fechar();

        $this->assertSame($antes, $contexto->id());
    }

    public function test_registra_em_log_o_excedente_de_quem_nao_tem_assinatura_ativa(): void
    {
        $this->notas(5, '2026-10-15 15:00:00');
        $this->assinatura->forceFill(['situacao' => StatusDaAssinatura::Cancelada])->save();
        Log::spy();

        $this->assertSame(0, $this->fechar());

        Log::shouldHaveReceived('info')->withArgs(fn (string $mensagem, array $contexto): bool => str_contains($mensagem, 'sem assinatura ativa') && $contexto['tenant_id'] === $this->tenant->id && $contexto['documentos_excedentes'] === 3)->once();
    }
}

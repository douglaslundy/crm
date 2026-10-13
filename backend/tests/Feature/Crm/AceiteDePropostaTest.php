<?php

declare(strict_types=1);

namespace Tests\Feature\Crm;

use App\Modules\Crm\Application\Actions\AceitarProposta;
use App\Modules\Crm\Application\Actions\CancelarProposta;
use App\Modules\Crm\Application\Actions\GanharNegocio;
use App\Modules\Crm\Application\Actions\RecusarProposta;
use App\Modules\Crm\Application\ResolvedorDePropostaPublica;
use App\Modules\Crm\Domain\Enums\StatusDaProposta;
use App\Modules\Crm\Domain\Enums\StatusDoNegocio;
use App\Modules\Crm\Domain\Enums\TipoDeRegistro;
use App\Modules\Crm\Domain\Exceptions\RegraDoCrmException;
use App\Modules\Crm\Domain\Models\Negocio;
use App\Modules\Crm\Domain\Models\Proposta;
use App\Modules\Crm\Domain\Models\RegistroDaTimeline;
use App\Modules\Identity\Domain\Models\Usuario;
use App\Modules\Platform\Domain\Enums\Modulo;
use App\Modules\Platform\Domain\Models\Plano;
use App\Modules\Tenancy\Domain\Models\Tenant;
use App\Modules\Tenancy\Domain\TenantContext;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ComCrm;
use Tests\Support\ComPropostas;
use Tests\TestCase;

class AceiteDePropostaTest extends TestCase
{
    use ComCrm;
    use ComPropostas;
    use RefreshDatabase;

    private Negocio $negocio;

    private Proposta $proposta;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararCrm();
        $this->negocio = $this->negocioComItens();
        $this->proposta = $this->propostaEnviada($this->negocio);
        $this->token = (string) $this->proposta->token;
        // A rota pública não tem tenant: o resolvedor fixa pelo hash do token.
        app(TenantContext::class)->clear();
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

    public function test_aceite_ganha_o_negocio_cancela_irmas_e_grava_timeline(): void
    {
        app(TenantContext::class)->set($this->tenant->id);
        $irma = $this->propostaEnviada($this->negocio);
        app(TenantContext::class)->clear();

        $p = app(AceitarProposta::class)->executar($this->token, '  Maria Souza ', '203.0.113.9');

        $this->assertSame(StatusDaProposta::Aceita, $p->status);
        $this->assertSame('Maria Souza', $p->aceite_nome);
        $this->assertSame('203.0.113.9', $p->aceite_ip);
        $this->assertNotNull($p->respondida_em);
        $this->assertSame(StatusDoNegocio::Ganho, $this->negocio->refresh()->status);
        $this->assertSame(StatusDaProposta::Cancelada, $irma->refresh()->status);
        $this->assertNull($irma->token_hash);
        $this->assertSame(1, RegistroDaTimeline::query()->where('tipo', TipoDeRegistro::PropostaAceita)->count());
        $this->assertSame(1, RegistroDaTimeline::query()->where('tipo', TipoDeRegistro::NegocioGanho)->count());
    }

    public function test_aceite_repetido_e_idempotente(): void
    {
        app(AceitarProposta::class)->executar($this->token, 'Maria', null);
        $segunda = app(AceitarProposta::class)->executar($this->token, 'Outra pessoa', null);

        $this->assertSame(StatusDaProposta::Aceita, $segunda->status);
        $this->assertSame('Maria', $segunda->aceite_nome);
        $this->assertSame(1, RegistroDaTimeline::query()->where('tipo', TipoDeRegistro::PropostaAceita)->count());
        $this->assertSame(1, RegistroDaTimeline::query()->where('tipo', TipoDeRegistro::NegocioGanho)->count());
    }

    public function test_recusa_nao_mexe_no_negocio(): void
    {
        $p = app(RecusarProposta::class)->executar($this->token, '  Caro demais ', null);

        $this->assertSame(StatusDaProposta::Recusada, $p->status);
        $this->assertSame('Caro demais', $p->motivo_recusa);
        $this->assertSame(StatusDoNegocio::Aberto, $this->negocio->refresh()->status);
        $this->assertSame(1, RegistroDaTimeline::query()->where('tipo', TipoDeRegistro::PropostaRecusada)->count());
    }

    public function test_recusa_sem_motivo_grava_nulo(): void
    {
        $p = app(RecusarProposta::class)->executar($this->token, '   ', null);

        $this->assertNull($p->motivo_recusa);
    }

    public function test_aceitar_depois_de_recusar_bloqueia(): void
    {
        app(RecusarProposta::class)->executar($this->token, null, null);

        $this->assertSame('PROPOSTA_JA_RESPONDIDA', $this->codigoDe(
            fn () => app(AceitarProposta::class)->executar($this->token, 'Maria', null),
        ));
    }

    public function test_expirada_bloqueia_aceite_e_recusa(): void
    {
        $this->travel(16)->days();

        $this->assertSame('PROPOSTA_EXPIRADA', $this->codigoDe(fn () => app(AceitarProposta::class)->executar($this->token, 'Maria', null)));
        $this->assertSame('PROPOSTA_EXPIRADA', $this->codigoDe(fn () => app(RecusarProposta::class)->executar($this->token, null, null)));
        $this->assertSame(StatusDoNegocio::Aberto, $this->negocio->refresh()->status);
    }

    public function test_aceite_sem_nome_bloqueia(): void
    {
        $this->assertSame('ACEITE_SEM_NOME', $this->codigoDe(fn () => app(AceitarProposta::class)->executar($this->token, '   ', null)));
    }

    public function test_token_invalido_ou_cancelado_e_nao_encontrado(): void
    {
        app(TenantContext::class)->set($this->tenant->id);
        app(CancelarProposta::class)->executar($this->admin, $this->proposta);
        app(TenantContext::class)->clear();

        foreach (['x', '', str_repeat('a', 64), $this->token] as $token) {
            try {
                app(ResolvedorDePropostaPublica::class)->resolver($token);
                $this->fail('Deveria não encontrar.');
            } catch (ModelNotFoundException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_token_resolve_so_a_propria_proposta_e_fixa_o_tenant(): void
    {
        $plano = Plano::factory()->comModulos(Modulo::Crm);
        $outro = Tenant::factory()->for($plano)->create();
        $dono = Usuario::factory()->for($outro)->create();
        app(TenantContext::class)->set($outro->id);
        $negocioB = Negocio::factory()->create(['responsavel_id' => $dono->id]);
        $negocioB->itens()->create(['tipo' => 'PRODUTO', 'item_id' => (string) \Illuminate\Support\Str::uuid(), 'descricao' => 'B', 'quantidade' => 1, 'valor_unitario_centavos' => 100]);
        $b = app(\App\Modules\Crm\Application\Actions\EnviarProposta::class)->executar(
            $dono,
            app(\App\Modules\Crm\Application\Actions\CriarProposta::class)->executar($dono, $negocioB),
        );
        app(TenantContext::class)->clear();

        $resolvida = app(ResolvedorDePropostaPublica::class)->resolver((string) $b->token);
        $this->assertSame($b->id, $resolvida->id);
        $this->assertSame($outro->id, app(TenantContext::class)->id());

        $minha = app(ResolvedorDePropostaPublica::class)->resolver($this->token);
        $this->assertSame($this->proposta->id, $minha->id);
        $this->assertSame($this->tenant->id, app(TenantContext::class)->id());
    }

    public function test_negocio_ja_ganho_pela_equipe_bloqueia_aceite_e_mantem_enviada(): void
    {
        app(TenantContext::class)->set($this->tenant->id);
        app(GanharNegocio::class)->executar($this->admin, $this->negocio);
        app(TenantContext::class)->clear();

        $this->assertSame('NEGOCIO_FECHADO', $this->codigoDe(fn () => app(AceitarProposta::class)->executar($this->token, 'Maria', null)));
        app(TenantContext::class)->set($this->tenant->id);
        $this->assertSame(StatusDaProposta::Enviada, $this->proposta->refresh()->status);
    }
}

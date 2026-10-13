<?php

declare(strict_types=1);

namespace Tests\Feature\Crm;

use App\Modules\Crm\Application\Actions\GanharNegocio;
use App\Modules\Crm\Application\Actions\MoverNegocio;
use App\Modules\Crm\Application\Actions\PerderNegocio;
use App\Modules\Crm\Domain\Enums\StatusDoNegocio;
use App\Modules\Crm\Domain\Events\NegocioGanho;
use App\Modules\Crm\Domain\Events\NegocioMovido;
use App\Modules\Crm\Domain\Events\NegocioPerdido;
use App\Modules\Crm\Domain\Exceptions\RegraDoCrmException;
use App\Modules\Crm\Domain\Models\Etapa;
use App\Modules\Crm\Domain\Models\Funil;
use App\Modules\Crm\Domain\Models\MotivoDePerda;
use App\Modules\Crm\Domain\Models\Negocio;
use App\Modules\Customers\Domain\Enums\EstagioCliente;
use App\Modules\Customers\Domain\Models\Cliente;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\Support\ComCrm;
use Tests\TestCase;

class MoverGanharPerderTest extends TestCase
{
    use ComCrm;
    use RefreshDatabase;

    private Funil $funil;

    private Etapa $origem;

    private Etapa $destino;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararCrm();
        $this->funil = Funil::factory()->padrao()->create();
        $this->origem = Etapa::factory()->create(['funil_id' => $this->funil->id, 'posicao' => 0]);
        $this->destino = Etapa::factory()->create(['funil_id' => $this->funil->id, 'posicao' => 1]);
    }

    /** @param array<string, mixed> $extra */
    private function negocio(Etapa $etapa, int $posicao, array $extra = []): Negocio
    {
        return Negocio::factory()->create(array_merge([
            'funil_id' => $this->funil->id, 'etapa_id' => $etapa->id,
            'responsavel_id' => $this->admin->id, 'posicao_na_etapa' => $posicao,
        ], $extra));
    }

    /** @return list<string> */
    private function ordem(Etapa $etapa): array
    {
        return Negocio::query()->where('etapa_id', $etapa->id)->orderBy('posicao_na_etapa')->pluck('id')->all();
    }

    public function test_mover_entre_etapas_reindexa_origem_e_destino(): void
    {
        Event::fake([NegocioMovido::class]);
        $o0 = $this->negocio($this->origem, 0);
        $o1 = $this->negocio($this->origem, 1);
        $o2 = $this->negocio($this->origem, 2);
        $d0 = $this->negocio($this->destino, 0);
        $d1 = $this->negocio($this->destino, 1);

        app(MoverNegocio::class)->executar($this->admin, $o1, $this->destino->id, 1);

        $this->assertSame([$o0->id, $o2->id], $this->ordem($this->origem));
        $this->assertSame([$d0->id, $o1->id, $d1->id], $this->ordem($this->destino));
        $this->assertSame([0, 1], Negocio::query()->where('etapa_id', $this->origem->id)->orderBy('posicao_na_etapa')->pluck('posicao_na_etapa')->all());
        $this->assertSame([0, 1, 2], Negocio::query()->where('etapa_id', $this->destino->id)->orderBy('posicao_na_etapa')->pluck('posicao_na_etapa')->all());
        Event::assertDispatched(NegocioMovido::class, fn (NegocioMovido $e): bool => $e->etapaOrigemId === $this->origem->id && $e->etapaDestinoId === $this->destino->id);
    }

    public function test_reordenar_na_mesma_etapa_nao_dispara_evento_de_movimento(): void
    {
        Event::fake([NegocioMovido::class]);
        $a = $this->negocio($this->origem, 0);
        $b = $this->negocio($this->origem, 1);

        app(MoverNegocio::class)->executar($this->admin, $b, $this->origem->id, 0);

        $this->assertSame([$b->id, $a->id], $this->ordem($this->origem));
        Event::assertNotDispatched(NegocioMovido::class);
    }

    public function test_posicao_acima_do_tamanho_vai_para_o_fim(): void
    {
        $a = $this->negocio($this->origem, 0);
        $d0 = $this->negocio($this->destino, 0);

        app(MoverNegocio::class)->executar($this->admin, $a, $this->destino->id, 99);

        $this->assertSame([$d0->id, $a->id], $this->ordem($this->destino));
    }

    public function test_etapa_de_outro_funil_e_recusada(): void
    {
        $alheia = Etapa::factory()->create();
        $negocio = $this->negocio($this->origem, 0);

        $this->expectException(RegraDoCrmException::class);

        app(MoverNegocio::class)->executar($this->admin, $negocio, $alheia->id, 0);
    }

    public function test_ganhar_converte_lead_e_dispara_evento(): void
    {
        Event::fake([NegocioGanho::class]);
        $lead = Cliente::factory()->create();
        $negocio = $this->negocio($this->origem, 0, ['cliente_id' => $lead->id]);

        $ganho = app(GanharNegocio::class)->executar($this->admin, $negocio);

        $this->assertSame(StatusDoNegocio::Ganho, $ganho->status);
        $this->assertNotNull($ganho->fechado_em);
        $this->assertSame(EstagioCliente::Cliente, $lead->fresh()->estagio);
        Event::assertDispatched(NegocioGanho::class);
    }

    public function test_perder_exige_motivo_ativo_e_nao_converte_lead(): void
    {
        Event::fake([NegocioPerdido::class]);
        $lead = Cliente::factory()->create();
        $negocio = $this->negocio($this->origem, 0, ['cliente_id' => $lead->id]);
        $inativo = MotivoDePerda::factory()->create(['ativo' => false]);
        $motivo = MotivoDePerda::factory()->create();

        try {
            app(PerderNegocio::class)->executar($this->admin, $negocio, $inativo->id, null);
            $this->fail('Deveria recusar.');
        } catch (RegraDoCrmException $e) {
            $this->assertSame('MOTIVO_INVALIDO', $e->codigo());
        }

        $perdido = app(PerderNegocio::class)->executar($this->admin, $negocio, $motivo->id, 'Foi para o concorrente');

        $this->assertSame(StatusDoNegocio::Perdido, $perdido->status);
        $this->assertSame($motivo->id, $perdido->motivo_de_perda_id);
        $this->assertSame(EstagioCliente::Lead, $lead->fresh()->estagio);
        Event::assertDispatched(NegocioPerdido::class);
    }

    public function test_negocio_fechado_nao_move(): void
    {
        $fechado = $this->negocio($this->origem, 0, ['status' => StatusDoNegocio::Ganho]);

        $this->expectException(RegraDoCrmException::class);

        app(MoverNegocio::class)->executar($this->admin, $fechado, $this->destino->id, 0);
    }

    public function test_ganhar_negocio_ja_fechado_e_recusado(): void
    {
        $fechado = $this->negocio($this->origem, 0, ['status' => StatusDoNegocio::Perdido]);

        $this->expectException(RegraDoCrmException::class);

        app(GanharNegocio::class)->executar($this->admin, $fechado);
    }
}

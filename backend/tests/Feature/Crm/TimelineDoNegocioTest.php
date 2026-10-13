<?php

declare(strict_types=1);

namespace Tests\Feature\Crm;

use App\Modules\Crm\Application\Actions\MoverNegocio;
use App\Modules\Crm\Application\Actions\PerderNegocio;
use App\Modules\Crm\Domain\Enums\TipoDeRegistro;
use App\Modules\Crm\Domain\Events\NegocioCriado;
use App\Modules\Crm\Domain\Events\NegocioMovido;
use App\Modules\Crm\Domain\Models\ConfiguracaoDoCrm;
use App\Modules\Crm\Domain\Models\Etapa;
use App\Modules\Crm\Domain\Models\Funil;
use App\Modules\Crm\Domain\Models\MotivoDePerda;
use App\Modules\Crm\Domain\Models\Negocio;
use App\Modules\Crm\Domain\Models\RegistroDaTimeline;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ComCrm;
use Tests\TestCase;

class TimelineDoNegocioTest extends TestCase
{
    use ComCrm;
    use RefreshDatabase;

    private Negocio $negocio;

    private Etapa $origem;

    private Etapa $destino;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararCrm();
        ConfiguracaoDoCrm::create(['vendedor_ve_so_os_seus' => false]);
        $funil = Funil::factory()->padrao()->create();
        $this->origem = Etapa::factory()->create(['funil_id' => $funil->id, 'nome' => 'Contato', 'posicao' => 0]);
        $this->destino = Etapa::factory()->create(['funil_id' => $funil->id, 'nome' => 'Proposta', 'posicao' => 1]);
        $this->negocio = Negocio::factory()->create([
            'titulo' => 'Brindes', 'funil_id' => $funil->id, 'etapa_id' => $this->origem->id, 'responsavel_id' => $this->admin->id,
        ]);
    }

    public function test_negocio_criado_grava_registro(): void
    {
        event(new NegocioCriado($this->tenant->id, $this->negocio->id, $this->negocio->cliente_id, $this->admin->id));

        $r = RegistroDaTimeline::query()->sole();
        $this->assertSame(TipoDeRegistro::NegocioCriado, $r->tipo);
        $this->assertSame($this->negocio->cliente_id, $r->cliente_id);
        $this->assertSame($this->negocio->id, $r->negocio_id);
        $this->assertSame($this->admin->id, $r->autor_id);
        $this->assertStringContainsString('Brindes', $r->texto);
    }

    public function test_mover_pela_action_grava_etapas_de_e_para_por_nome(): void
    {
        app(MoverNegocio::class)->executar($this->admin, $this->negocio, $this->destino->id, 0);

        $r = RegistroDaTimeline::query()->where('tipo', TipoDeRegistro::NegocioMovido)->sole();
        $this->assertSame('Contato', $r->dados['de']);
        $this->assertSame('Proposta', $r->dados['para']);
        $this->assertSame('Negócio movido de Contato para Proposta: Brindes', $r->texto);
    }

    public function test_perder_pela_action_grava_o_motivo(): void
    {
        $motivo = MotivoDePerda::factory()->create(['nome' => 'Preço']);

        app(PerderNegocio::class)->executar($this->admin, $this->negocio, $motivo->id, null);

        $r = RegistroDaTimeline::query()->where('tipo', TipoDeRegistro::NegocioPerdido)->sole();
        $this->assertSame('Preço', $r->dados['motivo']);
    }

    public function test_falha_do_listener_nao_derruba_o_evento(): void
    {
        // Negócio inexistente: o listener não acha o título e não pode propagar exceção.
        event(new NegocioMovido($this->tenant->id, '00000000-0000-4000-8000-000000000000', $this->negocio->cliente_id, null, $this->origem->id, $this->destino->id));

        $this->assertSame(0, RegistroDaTimeline::query()->count());
    }
}

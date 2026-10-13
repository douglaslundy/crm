<?php

declare(strict_types=1);

namespace Tests\Feature\Crm;

use App\Modules\Crm\Domain\Enums\TipoDeAtividade;
use App\Modules\Crm\Domain\Enums\TipoDeRegistro;
use App\Modules\Crm\Domain\Models\Atividade;
use App\Modules\Crm\Domain\Models\ConfiguracaoDoCrm;
use App\Modules\Crm\Domain\Models\Etapa;
use App\Modules\Crm\Domain\Models\Funil;
use App\Modules\Crm\Domain\Models\Negocio;
use App\Modules\Crm\Domain\Models\RegistroDaTimeline;
use App\Modules\Identity\Domain\Enums\Papel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ComCrm;
use Tests\TestCase;

class AtividadesModelosTest extends TestCase
{
    use ComCrm;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararCrm();
        ConfiguracaoDoCrm::create(['vendedor_ve_so_os_seus' => true]);
    }

    public function test_atividade_e_criada_aberta_com_tipo_e_responsavel(): void
    {
        $atividade = Atividade::factory()->create(['tipo' => TipoDeAtividade::Ligacao]);

        $this->assertSame(TipoDeAtividade::Ligacao, $atividade->refresh()->tipo);
        $this->assertFalse($atividade->estaConcluida());
        $this->assertSame($this->tenant->id, $atividade->tenant_id);
    }

    public function test_atividade_concluida_pela_factory(): void
    {
        $this->assertTrue(Atividade::factory()->concluida()->create()->estaConcluida());
    }

    public function test_vendedor_restrito_so_enxerga_as_suas_ou_de_negocios_dele(): void
    {
        $joao = $this->usuarioDoPapel(Papel::Vendedor);
        $maria = $this->usuarioDoPapel(Papel::Vendedor);
        $funil = Funil::factory()->padrao()->create();
        $etapa = Etapa::factory()->create(['funil_id' => $funil->id]);
        $negocioDoJoao = Negocio::factory()->create(['funil_id' => $funil->id, 'etapa_id' => $etapa->id, 'responsavel_id' => $joao->id]);

        $propria = Atividade::factory()->create(['responsavel_id' => $joao->id]);
        $doNegocio = Atividade::factory()->create(['responsavel_id' => $maria->id, 'negocio_id' => $negocioDoJoao->id, 'cliente_id' => $negocioDoJoao->cliente_id]);
        $alheia = Atividade::factory()->create(['responsavel_id' => $maria->id]);

        $ids = Atividade::query()->visiveisPara($joao)->pluck('id')->all();

        $this->assertEqualsCanonicalizing([$propria->id, $doNegocio->id], $ids);
        $this->assertNotContains($alheia->id, $ids);
        $this->assertCount(3, Atividade::query()->visiveisPara($this->admin)->get());
    }

    public function test_timeline_do_vendedor_restrito_traz_negocios_dele_e_o_que_ele_escreveu(): void
    {
        $joao = $this->usuarioDoPapel(Papel::Vendedor);
        $maria = $this->usuarioDoPapel(Papel::Vendedor);
        $funil = Funil::factory()->padrao()->create();
        $etapa = Etapa::factory()->create(['funil_id' => $funil->id]);
        $dele = Negocio::factory()->create(['funil_id' => $funil->id, 'etapa_id' => $etapa->id, 'responsavel_id' => $joao->id]);
        $dela = Negocio::factory()->create(['funil_id' => $funil->id, 'etapa_id' => $etapa->id, 'responsavel_id' => $maria->id, 'cliente_id' => $dele->cliente_id]);

        $base = ['cliente_id' => $dele->cliente_id, 'tipo' => TipoDeRegistro::NegocioCriado, 'texto' => 'x', 'ocorreu_em' => now()];
        $r1 = RegistroDaTimeline::create($base + ['negocio_id' => $dele->id]);
        RegistroDaTimeline::create($base + ['negocio_id' => $dela->id]);
        $nota = RegistroDaTimeline::create(['tipo' => TipoDeRegistro::Nota, 'autor_id' => $joao->id] + $base);
        RegistroDaTimeline::create(['tipo' => TipoDeRegistro::Nota, 'autor_id' => $maria->id] + $base);

        $ids = RegistroDaTimeline::query()->visiveisPara($joao)->pluck('id')->all();

        $this->assertEqualsCanonicalizing([$r1->id, $nota->id], $ids);
        $this->assertCount(4, RegistroDaTimeline::query()->visiveisPara($this->admin)->get());
    }

    public function test_etapa_guarda_dias_para_parado_nulavel(): void
    {
        $funil = Funil::factory()->padrao()->create();
        $etapa = Etapa::factory()->create(['funil_id' => $funil->id, 'dias_para_parado' => 7]);

        $this->assertSame(7, $etapa->refresh()->dias_para_parado);
        $this->assertNull(Etapa::factory()->create(['funil_id' => $funil->id])->refresh()->dias_para_parado);
    }
}

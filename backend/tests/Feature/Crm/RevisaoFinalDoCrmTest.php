<?php

declare(strict_types=1);

namespace Tests\Feature\Crm;

use App\Modules\Crm\Application\Actions\AtualizarNegocio;
use App\Modules\Crm\Application\Actions\DefinirItensDoNegocio;
use App\Modules\Crm\Application\Actions\MoverNegocio;
use App\Modules\Crm\Domain\Enums\StatusDoNegocio;
use App\Modules\Crm\Domain\Exceptions\RegraDoCrmException;
use App\Modules\Crm\Domain\Models\ConfiguracaoDoCrm;
use App\Modules\Crm\Domain\Models\Etapa;
use App\Modules\Crm\Domain\Models\Funil;
use App\Modules\Crm\Domain\Models\MotivoDePerda;
use App\Modules\Crm\Domain\Models\Negocio;
use App\Modules\Identity\Domain\Enums\Papel;
use App\Modules\Platform\Domain\Enums\Modulo;
use App\Modules\Platform\Domain\Models\Plano;
use App\Modules\Tenancy\Domain\Models\Tenant;
use App\Modules\Tenancy\Domain\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ComCrm;
use Tests\TestCase;

/** Achados da revisão final da F7a. */
class RevisaoFinalDoCrmTest extends TestCase
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
        ConfiguracaoDoCrm::create(['vendedor_ve_so_os_seus' => true]);
        $this->funil = Funil::factory()->padrao()->create();
        $this->origem = Etapa::factory()->create(['funil_id' => $this->funil->id, 'posicao' => 0]);
        $this->destino = Etapa::factory()->create(['funil_id' => $this->funil->id, 'posicao' => 1]);
    }

    /** @param array<string, mixed> $extra */
    private function negocio(Etapa $etapa, string $responsavelId, int $posicao, array $extra = []): Negocio
    {
        return Negocio::factory()->create(array_merge([
            'funil_id' => $this->funil->id, 'etapa_id' => $etapa->id,
            'responsavel_id' => $responsavelId, 'posicao_na_etapa' => $posicao,
        ], $extra));
    }

    public function test_posicao_do_vendedor_e_relativa_a_lista_que_ele_enxerga(): void
    {
        $joao = $this->usuarioDoPapel(Papel::Vendedor);
        $maria = $this->usuarioDoPapel(Papel::Vendedor);
        $m0 = $this->negocio($this->destino, $maria->id, 0);
        $m1 = $this->negocio($this->destino, $maria->id, 1);
        $j0 = $this->negocio($this->destino, $joao->id, 2);
        $j1 = $this->negocio($this->origem, $joao->id, 0);

        // Para o João a coluna tem só o j0; a posição 0 é "antes do j0".
        app(MoverNegocio::class)->executar($joao, $j1, $this->destino->id, 0);

        $ordem = Negocio::query()->where('etapa_id', $this->destino->id)->orderBy('posicao_na_etapa')->pluck('id')->all();
        $this->assertSame([$m0->id, $m1->id, $j1->id, $j0->id], $ordem);
    }

    public function test_definir_itens_recusa_negocio_que_fechou_depois_de_carregado(): void
    {
        $negocio = $this->negocio($this->origem, $this->admin->id, 0);
        Negocio::query()->whereKey($negocio->id)->update(['status' => StatusDoNegocio::Ganho]);

        try {
            app(DefinirItensDoNegocio::class)->executar($this->admin, $negocio, []);
            $this->fail('Deveria recusar.');
        } catch (RegraDoCrmException $e) {
            $this->assertSame('NEGOCIO_FECHADO', $e->codigo());
        }
    }

    public function test_atualizar_recusa_negocio_que_fechou_depois_de_carregado(): void
    {
        $negocio = $this->negocio($this->origem, $this->admin->id, 0);
        Negocio::query()->whereKey($negocio->id)->update(['status' => StatusDoNegocio::Perdido]);

        $this->expectException(RegraDoCrmException::class);

        app(AtualizarNegocio::class)->executar($this->admin, $negocio, ['titulo' => 'Novo']);
    }

    public function test_filtro_que_nao_e_uuid_devolve_422_e_id_que_nao_e_uuid_devolve_404(): void
    {
        $this->spaComo($this->admin)->getJson('/api/app/crm/negocios?funil_id=abc')->assertStatus(422);
        $this->spaComo($this->admin)->getJson('/api/app/crm/negocios/abc')->assertNotFound();
        $this->spaComo($this->admin)->putJson('/api/app/crm/etapas/abc', ['nome' => 'x'])->assertNotFound();
    }

    public function test_isolamento_nos_demais_endpoints_de_escrita(): void
    {
        $outra = Tenant::factory()->for(Plano::factory()->comModulos(Modulo::Crm))->create();
        $donoAlheio = $this->usuarioDoPapel(Papel::Admin, $outra);
        app(TenantContext::class)->set($outra->id);
        $funilAlheio = Funil::factory()->create();
        $etapaAlheia = Etapa::factory()->create(['funil_id' => $funilAlheio->id]);
        $motivoAlheio = MotivoDePerda::factory()->create();
        $negocioAlheio = Negocio::factory()->create(['funil_id' => $funilAlheio->id, 'etapa_id' => $etapaAlheia->id, 'responsavel_id' => $donoAlheio->id]);
        app(TenantContext::class)->set($this->tenant->id);

        $meu = $this->negocio($this->origem, $this->admin->id, 0);
        $motivoMeu = MotivoDePerda::factory()->create();
        $admin = $this->admin;

        $this->spaComo($admin)->putJson("/api/app/crm/etapas/{$etapaAlheia->id}", ['nome' => 'x'])->assertNotFound();
        $this->spaComo($admin)->putJson("/api/app/crm/funis/{$funilAlheio->id}/etapas/ordem", ['etapas' => [$etapaAlheia->id]])->assertNotFound();
        $this->spaComo($admin)->putJson("/api/app/crm/motivos-de-perda/{$motivoAlheio->id}", ['nome' => 'x'])->assertNotFound();
        $this->spaComo($admin)->postJson("/api/app/crm/negocios/{$negocioAlheio->id}/perder", ['motivo_de_perda_id' => $motivoMeu->id])->assertNotFound();
        $this->spaComo($admin)->postJson("/api/app/crm/negocios/{$meu->id}/mover", ['etapa_id' => $etapaAlheia->id, 'posicao' => 0])->assertStatus(422);
        $this->spaComo($admin)->postJson("/api/app/crm/negocios/{$meu->id}/perder", ['motivo_de_perda_id' => $motivoAlheio->id])->assertStatus(422);
        $this->spaComo($admin)->postJson('/api/app/crm/negocios', [
            'titulo' => 'X', 'funil_id' => $funilAlheio->id, 'etapa_id' => $etapaAlheia->id, 'novo_lead' => ['nome' => 'A', 'telefone' => '1'],
        ])->assertStatus(422);
    }
}

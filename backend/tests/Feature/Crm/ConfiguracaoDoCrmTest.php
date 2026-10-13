<?php

declare(strict_types=1);

namespace Tests\Feature\Crm;

use App\Modules\Crm\Application\Actions\AtualizarConfiguracao;
use App\Modules\Crm\Application\Actions\AtualizarEtapa;
use App\Modules\Crm\Application\Actions\AtualizarFunil;
use App\Modules\Crm\Application\Actions\AtualizarMotivoDePerda;
use App\Modules\Crm\Application\Actions\CriarEtapa;
use App\Modules\Crm\Application\Actions\CriarFunil;
use App\Modules\Crm\Application\Actions\CriarMotivoDePerda;
use App\Modules\Crm\Application\Actions\ReordenarEtapas;
use App\Modules\Crm\Application\PreparadorDoCrm;
use App\Modules\Crm\Domain\Exceptions\RegraDoCrmException;
use App\Modules\Crm\Domain\Models\ConfiguracaoDoCrm;
use App\Modules\Crm\Domain\Models\Etapa;
use App\Modules\Crm\Domain\Models\Funil;
use App\Modules\Crm\Domain\Models\MotivoDePerda;
use App\Modules\Crm\Domain\Models\Negocio;
use App\Modules\Identity\Domain\Enums\Papel;
use App\Modules\Identity\Domain\Models\Usuario;
use App\Modules\Platform\Domain\Exceptions\ModuloNaoContratadoException;
use App\Modules\Platform\Domain\Models\Plano;
use App\Modules\Shared\Domain\Exceptions\AcessoNegadoException;
use App\Modules\Tenancy\Domain\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ComCrm;
use Tests\TestCase;

class ConfiguracaoDoCrmTest extends TestCase
{
    use ComCrm;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararCrm();
    }

    public function test_preparador_semeia_funil_etapas_e_motivos_uma_unica_vez(): void
    {
        app(PreparadorDoCrm::class)->garantir($this->admin);
        app(PreparadorDoCrm::class)->garantir($this->admin);

        $funil = Funil::query()->where('padrao', true)->sole();
        $this->assertSame('Vendas', $funil->nome);
        $this->assertSame([10, 40, 70], $funil->etapas->pluck('probabilidade')->all());
        $this->assertSame(4, MotivoDePerda::query()->count());
        $this->assertTrue(ConfiguracaoDoCrm::vendedorVeSoOsSeus());
    }

    public function test_so_proprietario_e_admin_configuram(): void
    {
        $this->expectException(AcessoNegadoException::class);

        app(CriarFunil::class)->executar($this->usuarioDoPapel(Papel::Vendedor), 'Pós-venda');
    }

    public function test_fiscal_nao_tem_acesso_ao_crm(): void
    {
        $this->expectException(AcessoNegadoException::class);

        app(CriarFunil::class)->executar($this->usuarioDoPapel(Papel::Fiscal), 'Pós-venda');
    }

    public function test_empresa_sem_modulo_crm_e_barrada(): void
    {
        $tenant = Tenant::factory()->for(Plano::factory())->create();
        $admin = Usuario::factory()->for($tenant)->create(['papel' => Papel::Admin]);

        $this->expectException(ModuloNaoContratadoException::class);

        app(CriarFunil::class)->executar($admin, 'Pós-venda');
    }

    public function test_cria_funil_e_etapa_nas_posicoes_seguintes(): void
    {
        $funil = app(CriarFunil::class)->executar($this->admin, 'Pós-venda');
        $a = app(CriarEtapa::class)->executar($this->admin, $funil, 'Aberto', 0);
        $b = app(CriarEtapa::class)->executar($this->admin, $funil, 'Resolvido', 100);

        $this->assertSame([0, 1], [$a->posicao, $b->posicao]);
    }

    public function test_probabilidade_fora_de_0_a_100_e_recusada(): void
    {
        $funil = Funil::factory()->create();

        $this->expectException(RegraDoCrmException::class);

        app(CriarEtapa::class)->executar($this->admin, $funil, 'X', 101);
    }

    public function test_funil_padrao_nao_desativa(): void
    {
        $funil = Funil::factory()->padrao()->create();

        try {
            app(AtualizarFunil::class)->executar($this->admin, $funil, ['ativo' => false]);
            $this->fail('Deveria recusar.');
        } catch (RegraDoCrmException $e) {
            $this->assertSame('FUNIL_PADRAO_PROTEGIDO', $e->codigo());
        }
    }

    public function test_etapa_com_negocio_aberto_nao_desativa_mas_sem_negocio_sim(): void
    {
        $funil = Funil::factory()->create();
        $comNegocio = Etapa::factory()->create(['funil_id' => $funil->id]);
        $vazia = Etapa::factory()->create(['funil_id' => $funil->id]);
        Negocio::factory()->create(['funil_id' => $funil->id, 'etapa_id' => $comNegocio->id]);

        app(AtualizarEtapa::class)->executar($this->admin, $vazia, ['ativa' => false]);
        $this->assertFalse($vazia->fresh()->ativa);

        $this->expectException(RegraDoCrmException::class);
        app(AtualizarEtapa::class)->executar($this->admin, $comNegocio, ['ativa' => false]);
    }

    public function test_reordenar_exige_exatamente_as_etapas_do_funil(): void
    {
        $funil = Funil::factory()->create();
        $a = Etapa::factory()->create(['funil_id' => $funil->id, 'posicao' => 0]);
        $b = Etapa::factory()->create(['funil_id' => $funil->id, 'posicao' => 1]);

        app(ReordenarEtapas::class)->executar($this->admin, $funil, [$b->id, $a->id]);
        $this->assertSame([$b->id, $a->id], $funil->etapas()->pluck('id')->all());

        $this->expectException(RegraDoCrmException::class);
        app(ReordenarEtapas::class)->executar($this->admin, $funil, [$a->id]);
    }

    public function test_motivo_so_desativa_e_interruptor_atualiza(): void
    {
        $motivo = app(CriarMotivoDePerda::class)->executar($this->admin, 'Preço');
        app(AtualizarMotivoDePerda::class)->executar($this->admin, $motivo, ['ativo' => false]);
        $this->assertFalse($motivo->fresh()->ativo);

        app(AtualizarConfiguracao::class)->executar($this->admin, false);
        $this->assertFalse(ConfiguracaoDoCrm::vendedorVeSoOsSeus());
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature\Crm;

use App\Modules\Crm\Application\Actions\AtualizarAtividade;
use App\Modules\Crm\Application\Actions\ConcluirAtividade;
use App\Modules\Crm\Application\Actions\CriarAtividade;
use App\Modules\Crm\Application\Actions\ExcluirAtividade;
use App\Modules\Crm\Application\Actions\ReabrirAtividade;
use App\Modules\Crm\Domain\Enums\TipoDeAtividade;
use App\Modules\Crm\Domain\Enums\TipoDeRegistro;
use App\Modules\Crm\Domain\Exceptions\RegraDoCrmException;
use App\Modules\Crm\Domain\Models\Atividade;
use App\Modules\Crm\Domain\Models\ConfiguracaoDoCrm;
use App\Modules\Crm\Domain\Models\Etapa;
use App\Modules\Crm\Domain\Models\Funil;
use App\Modules\Crm\Domain\Models\Negocio;
use App\Modules\Crm\Domain\Models\RegistroDaTimeline;
use App\Modules\Customers\Domain\Models\Cliente;
use App\Modules\Identity\Domain\Enums\Papel;
use App\Modules\Shared\Domain\Exceptions\AcessoNegadoException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ComCrm;
use Tests\TestCase;

class AtividadesActionsTest extends TestCase
{
    use ComCrm;
    use RefreshDatabase;

    private Cliente $cliente;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararCrm();
        ConfiguracaoDoCrm::create(['vendedor_ve_so_os_seus' => true]);
        $this->cliente = Cliente::factory()->create();
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function dados(array $extra = []): array
    {
        return array_merge([
            'tipo' => 'LIGACAO', 'titulo' => 'Ligar para Ana', 'agendada_para' => '2026-10-14T12:00:00-03:00',
            'cliente_id' => $this->cliente->id,
        ], $extra);
    }

    private function negocioDe(string $responsavelId, ?string $clienteId = null): Negocio
    {
        $funil = Funil::factory()->padrao()->create();
        $etapa = Etapa::factory()->create(['funil_id' => $funil->id]);

        return Negocio::factory()->create([
            'funil_id' => $funil->id, 'etapa_id' => $etapa->id, 'responsavel_id' => $responsavelId,
            'cliente_id' => $clienteId ?? $this->cliente->id,
        ]);
    }

    public function test_cria_atividade_com_o_autor_como_responsavel_e_data_em_utc(): void
    {
        $a = app(CriarAtividade::class)->executar($this->admin, $this->dados());

        $this->assertSame($this->admin->id, $a->responsavel_id);
        $this->assertSame($this->admin->id, $a->criado_por);
        $this->assertSame(TipoDeAtividade::Ligacao, $a->tipo);
        $this->assertSame('2026-10-14 15:00:00', $a->agendada_para->utc()->format('Y-m-d H:i:s'));
        $this->assertNull($a->negocio_id);
    }

    public function test_negocio_informado_define_o_cliente(): void
    {
        $negocio = $this->negocioDe($this->admin->id);

        $a = app(CriarAtividade::class)->executar($this->admin, $this->dados(['cliente_id' => null, 'negocio_id' => $negocio->id]));

        $this->assertSame($negocio->cliente_id, $a->cliente_id);
        $this->assertSame($negocio->id, $a->negocio_id);
    }

    public function test_negocio_de_outro_cliente_e_recusado(): void
    {
        $negocio = $this->negocioDe($this->admin->id);
        $outro = Cliente::factory()->create();

        $this->expectException(RegraDoCrmException::class);
        app(CriarAtividade::class)->executar($this->admin, $this->dados(['cliente_id' => $outro->id, 'negocio_id' => $negocio->id]));
    }

    public function test_cliente_inexistente_e_recusado(): void
    {
        $this->expectException(RegraDoCrmException::class);
        app(CriarAtividade::class)->executar($this->admin, $this->dados(['cliente_id' => '00000000-0000-4000-8000-000000000000']));
    }

    public function test_vendedor_nao_atribui_a_outro_usuario(): void
    {
        $joao = $this->usuarioDoPapel(Papel::Vendedor);
        $maria = $this->usuarioDoPapel(Papel::Vendedor);

        $this->expectException(AcessoNegadoException::class);
        app(CriarAtividade::class)->executar($joao, $this->dados(['responsavel_id' => $maria->id]));
    }

    public function test_admin_atribui_a_usuario_da_empresa_e_recusa_de_fora(): void
    {
        $maria = $this->usuarioDoPapel(Papel::Vendedor);
        $a = app(CriarAtividade::class)->executar($this->admin, $this->dados(['responsavel_id' => $maria->id]));
        $this->assertSame($maria->id, $a->responsavel_id);

        $this->expectException(RegraDoCrmException::class);
        app(CriarAtividade::class)->executar($this->admin, $this->dados(['responsavel_id' => '00000000-0000-4000-8000-000000000000']));
    }

    public function test_leitura_nao_cria(): void
    {
        $this->expectException(AcessoNegadoException::class);
        app(CriarAtividade::class)->executar($this->usuarioDoPapel(Papel::Leitura), $this->dados());
    }

    public function test_concluir_grava_registro_na_timeline_uma_unica_vez(): void
    {
        $a = Atividade::factory()->create(['cliente_id' => $this->cliente->id, 'titulo' => 'Enviar proposta', 'tipo' => TipoDeAtividade::Email]);

        app(ConcluirAtividade::class)->executar($this->admin, $a);
        app(ConcluirAtividade::class)->executar($this->admin, $a->refresh());

        $this->assertTrue($a->refresh()->estaConcluida());
        $r = RegistroDaTimeline::query()->where('tipo', TipoDeRegistro::AtividadeConcluida)->sole();
        $this->assertSame($a->id, $r->atividade_id);
        $this->assertSame($this->cliente->id, $r->cliente_id);
        $this->assertSame('EMAIL', $r->dados['tipo']);
    }

    public function test_reabrir_remove_o_registro_e_e_idempotente(): void
    {
        $a = Atividade::factory()->create(['cliente_id' => $this->cliente->id]);
        app(ConcluirAtividade::class)->executar($this->admin, $a);

        app(ReabrirAtividade::class)->executar($this->admin, $a->refresh());
        app(ReabrirAtividade::class)->executar($this->admin, $a->refresh());

        $this->assertFalse($a->refresh()->estaConcluida());
        $this->assertSame(0, RegistroDaTimeline::query()->count());
    }

    public function test_excluir_nao_apaga_registro_ja_gravado(): void
    {
        $a = Atividade::factory()->create(['cliente_id' => $this->cliente->id]);
        app(ConcluirAtividade::class)->executar($this->admin, $a);

        app(ExcluirAtividade::class)->executar($this->admin, $a->refresh());

        $this->assertSame(0, Atividade::query()->count());
        $this->assertSame(1, RegistroDaTimeline::query()->count());
    }

    public function test_atualizar_muda_titulo_data_e_responsavel(): void
    {
        $a = Atividade::factory()->create(['cliente_id' => $this->cliente->id]);
        $maria = $this->usuarioDoPapel(Papel::Vendedor);

        $a = app(AtualizarAtividade::class)->executar($this->admin, $a, [
            'titulo' => 'Novo título', 'agendada_para' => '2026-10-20T09:00:00Z', 'responsavel_id' => $maria->id,
        ]);

        $this->assertSame('Novo título', $a->titulo);
        $this->assertSame('2026-10-20 09:00:00', $a->agendada_para->utc()->format('Y-m-d H:i:s'));
        $this->assertSame($maria->id, $a->responsavel_id);
    }
}

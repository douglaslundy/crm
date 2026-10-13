<?php

declare(strict_types=1);

namespace Tests\Feature\Crm;

use App\Modules\Catalog\Domain\Models\Produto;
use App\Modules\Catalog\Domain\Models\Servico;
use App\Modules\Crm\Application\Actions\AceitarProposta;
use App\Modules\Crm\Application\Actions\CriarProposta;
use App\Modules\Crm\Application\Actions\EnviarProposta;
use App\Modules\Crm\Application\Actions\GerarRascunhoDeNotaDaProposta;
use App\Modules\Crm\Domain\Enums\TipoDeRegistro;
use App\Modules\Crm\Domain\Exceptions\RegraDoCrmException;
use App\Modules\Crm\Domain\Models\Etapa;
use App\Modules\Crm\Domain\Models\Funil;
use App\Modules\Crm\Domain\Models\Negocio;
use App\Modules\Crm\Domain\Models\Proposta;
use App\Modules\Crm\Domain\Models\RegistroDaTimeline;
use App\Modules\Fiscal\Domain\Enums\ModeloDocumento;
use App\Modules\Fiscal\Domain\Enums\StatusNota;
use App\Modules\Fiscal\Domain\Models\Nota;
use App\Modules\Identity\Domain\Enums\Papel;
use App\Modules\Identity\Domain\Models\Usuario;
use App\Modules\Platform\Domain\Enums\Modulo;
use App\Modules\Platform\Domain\Enums\Recurso;
use App\Modules\Platform\Domain\Models\Plano;
use App\Modules\Tenancy\Domain\Models\Tenant;
use App\Modules\Tenancy\Domain\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\ComCrm;
use Tests\Support\ComPropostas;
use Tests\TestCase;

class RascunhoDeNotaDaPropostaTest extends TestCase
{
    use ComCrm;
    use ComPropostas;
    use RefreshDatabase;

    /** @param list<Modulo> $modulos */
    private function prepararComModulos(array $modulos): void
    {
        $plano = Plano::factory()->comModulos(...$modulos)->comLimites([Recurso::Clientes->value => Plano::ILIMITADO]);
        $this->tenant = Tenant::factory()->for($plano)->create();
        $this->admin = Usuario::factory()->for($this->tenant)->create(['papel' => Papel::Admin]);
        app(TenantContext::class)->set($this->tenant->id);
    }

    /** @param list<array{0: string, 1: string, 2: int, 3: int}> $itens tipo, id, quantidade, unitário */
    private function aceita(array $itens, int $desconto = 0): Proposta
    {
        $funil = Funil::factory()->padrao()->create();
        $etapa = Etapa::factory()->create(['funil_id' => $funil->id, 'posicao' => 0]);
        $negocio = Negocio::factory()->create(['funil_id' => $funil->id, 'etapa_id' => $etapa->id, 'responsavel_id' => $this->admin->id]);
        foreach ($itens as [$tipo, $id, $quantidade, $unitario]) {
            $negocio->itens()->create([
                'tipo' => $tipo, 'item_id' => $id, 'descricao' => 'Item '.$tipo, 'quantidade' => $quantidade, 'valor_unitario_centavos' => $unitario,
            ]);
        }
        $proposta = app(CriarProposta::class)->executar($this->admin, $negocio, ['desconto_centavos' => $desconto]);
        $proposta = app(EnviarProposta::class)->executar($this->admin, $proposta);

        return app(AceitarProposta::class)->executar((string) $proposta->token, 'Maria', null);
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

    public function test_so_produtos_gera_um_rascunho_nfe_com_snapshot_e_desconto(): void
    {
        $this->prepararComModulos([Modulo::Crm, Modulo::FiscalNfe]);
        $produto = Produto::factory()->create(['preco_centavos' => 99999]);
        $proposta = $this->aceita([['PRODUTO', $produto->id, 2, 1500]], desconto: 500);

        $ids = app(GerarRascunhoDeNotaDaProposta::class)->executar($this->admin, $proposta);

        $this->assertCount(1, $ids);
        $nota = Nota::query()->with(['itens', 'pagamentos'])->findOrFail($ids[0]);
        $this->assertSame(ModeloDocumento::Nfe, $nota->modelo);
        $this->assertSame(StatusNota::Rascunho, $nota->status);
        $this->assertSame($proposta->cliente_id, $nota->cliente_id);
        $this->assertSame(500, $nota->desconto_centavos);
        $this->assertSame(2500, $nota->total_centavos);
        $this->assertSame(1500, $nota->itens->first()->valor_unitario_centavos);
        $this->assertCount(0, $nota->pagamentos);
        $this->assertSame($ids, $proposta->refresh()->notas_ids);
    }

    public function test_so_servicos_gera_dps(): void
    {
        $this->prepararComModulos([Modulo::Crm, Modulo::FiscalNfse]);
        $servico = Servico::factory()->completo()->create();
        $proposta = $this->aceita([['SERVICO', $servico->id, 1, 20000]]);

        $ids = app(GerarRascunhoDeNotaDaProposta::class)->executar($this->admin, $proposta);

        $this->assertCount(1, $ids);
        $this->assertSame(ModeloDocumento::Dps, Nota::query()->findOrFail($ids[0])->modelo);
    }

    public function test_mista_gera_duas_notas_com_desconto_rateado(): void
    {
        $this->prepararComModulos([Modulo::Crm, Modulo::FiscalNfe, Modulo::FiscalNfse]);
        $produto = Produto::factory()->create();
        $servico = Servico::factory()->completo()->create();
        $proposta = $this->aceita([['PRODUTO', $produto->id, 1, 10000], ['SERVICO', $servico->id, 1, 10000]], desconto: 1000);

        $ids = app(GerarRascunhoDeNotaDaProposta::class)->executar($this->admin, $proposta);

        $this->assertCount(2, $ids);
        $notas = Nota::query()->whereIn('id', $ids)->get();
        $this->assertSame(1000, (int) $notas->sum('desconto_centavos'));
        $this->assertEqualsCanonicalizing(['NFE', 'DPS'], $notas->map(fn (Nota $n): string => $n->modelo->value)->all());
    }

    public function test_segunda_chamada_devolve_os_mesmos_ids_sem_criar_nota(): void
    {
        $this->prepararComModulos([Modulo::Crm, Modulo::FiscalNfe]);
        $proposta = $this->aceita([['PRODUTO', Produto::factory()->create()->id, 1, 1000]]);

        $primeira = app(GerarRascunhoDeNotaDaProposta::class)->executar($this->admin, $proposta);
        $segunda = app(GerarRascunhoDeNotaDaProposta::class)->executar($this->admin, $proposta->refresh());

        $this->assertSame($primeira, $segunda);
        $this->assertSame(1, Nota::query()->count());
    }

    public function test_proposta_nao_aceita_bloqueia(): void
    {
        $this->prepararComModulos([Modulo::Crm, Modulo::FiscalNfe]);
        $negocio = $this->negocioComItens(tipo: 'PRODUTO', itemId: Produto::factory()->create()->id);
        $enviada = $this->propostaEnviada($negocio);

        $this->assertSame('PROPOSTA_NAO_ACEITA', $this->codigoDe(
            fn () => app(GerarRascunhoDeNotaDaProposta::class)->executar($this->admin, $enviada),
        ));
    }

    public function test_plano_sem_fiscal_bloqueia_com_mensagem(): void
    {
        $this->prepararComModulos([Modulo::Crm]);
        $proposta = $this->aceita([['PRODUTO', (string) Str::uuid(), 1, 1000]]);

        try {
            app(GerarRascunhoDeNotaDaProposta::class)->executar($this->admin, $proposta);
            $this->fail('Deveria bloquear.');
        } catch (RegraDoCrmException $e) {
            $this->assertSame('FISCAL_INDISPONIVEL', $e->codigo());
            $this->assertStringContainsString('plano', $e->getMessage());
        }
        $this->assertSame(0, Nota::query()->count());
    }

    public function test_grava_registro_na_timeline(): void
    {
        $this->prepararComModulos([Modulo::Crm, Modulo::FiscalNfe]);
        $proposta = $this->aceita([['PRODUTO', Produto::factory()->create()->id, 1, 1000]]);

        app(GerarRascunhoDeNotaDaProposta::class)->executar($this->admin, $proposta);

        $this->assertSame(1, RegistroDaTimeline::query()->where('tipo', TipoDeRegistro::PropostaNotaGerada)->count());
    }
}

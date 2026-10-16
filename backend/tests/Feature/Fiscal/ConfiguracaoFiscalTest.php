<?php

declare(strict_types=1);

namespace Tests\Feature\Fiscal;

use App\Modules\Customers\Domain\Models\Cliente;
use App\Modules\Fiscal\Domain\Enums\StatusNota;
use App\Modules\Fiscal\Domain\Models\Emitente;
use App\Modules\Fiscal\Domain\Models\EmitenteSerie;
use App\Modules\Fiscal\Domain\Models\Nota;
use App\Modules\Identity\Domain\Enums\Papel;
use App\Modules\Identity\Domain\Models\Usuario;
use App\Modules\Platform\Domain\Models\Plano;
use App\Modules\Tenancy\Domain\Models\Tenant;
use App\Modules\Tenancy\Domain\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class ConfiguracaoFiscalTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Usuario $admin;

    protected function setUp(): void
    {
        parent::setUp();
        // Plano sem FISCAL_NFCE: o CSC não é exigido, mas continua aceito.
        $this->tenant = Tenant::factory()->for(Plano::factory())->create();
        $this->admin = Usuario::factory()->for($this->tenant)->create(['papel' => Papel::Admin]);
    }

    public function test_salva_csc_mesmo_sem_o_plano_ter_nfce(): void
    {
        $this->spa()->actingAs($this->admin)->putJson('/api/app/emitente/csc', [
            'csc_id_homologacao' => '1', 'csc_token_homologacao' => 'token-homolog',
        ])->assertOk()->assertJsonPath('data.csc_homologacao_configurado', true);

        $this->assertDatabaseMissing('emitentes', ['csc_token_homologacao_encrypted' => 'token-homolog']);
    }

    public function test_salva_serie_de_nfe(): void
    {
        $this->spa()->actingAs($this->admin)->putJson('/api/app/emitente/series/NFE', [
            'serie' => '1', 'proximo_numero' => 1,
        ])->assertOk();

        $resposta = $this->spa()->actingAs($this->admin)->getJson('/api/app/emitente')->assertOk();
        $this->assertSame('NFE', $resposta->json('data.series.0.modelo'));
        $this->assertSame(1, $resposta->json('data.series.0.proximo_numero'));
    }

    public function test_atualizar_serie_existente_substitui_em_vez_de_duplicar(): void
    {
        $this->spa()->actingAs($this->admin)->putJson('/api/app/emitente/series/NFE', ['serie' => '1', 'proximo_numero' => 1])->assertOk();
        $this->spa()->actingAs($this->admin)->putJson('/api/app/emitente/series/NFE', ['serie' => '2', 'proximo_numero' => 50])->assertOk();

        $resposta = $this->spa()->actingAs($this->admin)->getJson('/api/app/emitente')->assertOk();
        $resposta->assertJsonCount(1, 'data.series');
        $this->assertSame(50, $resposta->json('data.series.0.proximo_numero'));
    }

    private function notaNumerada(int $numero, string $serie = '1', string $ambiente = 'HOMOLOGACAO'): void
    {
        app(TenantContext::class)->set($this->tenant->id);
        $emitente = Emitente::query()->firstOrCreate([], []);
        Nota::factory()->create(['emitente_id' => $emitente->id, 'cliente_id' => Cliente::factory()->create()->id])
            ->forceFill(['status' => StatusNota::Autorizada, 'modelo' => 'NFE', 'serie' => $serie, 'numero' => $numero, 'ambiente' => $ambiente])->save();
    }

    public function test_proximo_numero_nao_pode_voltar_para_antes_do_ultimo_emitido(): void
    {
        $this->notaNumerada(5);

        $this->spa()->actingAs($this->admin)->putJson('/api/app/emitente/series/NFE', ['serie' => '1', 'proximo_numero' => 5])
            ->assertStatus(422)->assertJsonPath('codigo', 'FISCAL_NUMERO_JA_EMITIDO');
        $this->spa()->actingAs($this->admin)->putJson('/api/app/emitente/series/NFE', ['serie' => '1', 'proximo_numero' => 3])
            ->assertStatus(422)->assertJsonPath('codigo', 'FISCAL_NUMERO_JA_EMITIDO');
        $this->spa()->actingAs($this->admin)->putJson('/api/app/emitente/series/NFE', ['serie' => '1', 'proximo_numero' => 6])->assertOk();
    }

    public function test_serie_nova_ou_ambiente_novo_pode_recomecar_do_um(): void
    {
        $this->notaNumerada(5);

        $this->spa()->actingAs($this->admin)->putJson('/api/app/emitente/series/NFE', ['serie' => '2', 'proximo_numero' => 1])->assertOk();

        // A numeração de homologação e a de produção são separadas na SEFAZ: a nota de outro ambiente não trava o contador.
        Nota::query()->update(['ambiente' => 'PRODUCAO']);
        $this->spa()->actingAs($this->admin)->putJson('/api/app/emitente/series/NFE', ['serie' => '1', 'proximo_numero' => 1])->assertOk();
    }

    public function test_confirmar_producao_exige_a_flag_explicita(): void
    {
        $this->spa()->actingAs($this->admin)->postJson('/api/app/emitente/ambiente/producao', [])
            ->assertStatus(422)->assertJsonValidationErrors('confirmo');
    }

    private function nfeDeTesteAutorizada(): void
    {
        app(TenantContext::class)->set($this->tenant->id);
        $emitente = Emitente::query()->firstOrCreate([], []);
        $nota = Nota::factory()->create([
            'emitente_id' => $emitente->id, 'cliente_id' => Cliente::factory()->create()->id,
        ]);
        $nota->forceFill(['status' => StatusNota::Autorizada, 'ambiente' => 'HOMOLOGACAO'])->save();
    }

    public function test_confirmar_producao_muda_o_ambiente_e_audita(): void
    {
        $this->nfeDeTesteAutorizada();

        $this->spa()->actingAs($this->admin)->postJson('/api/app/emitente/ambiente/producao', ['confirmo' => true])
            ->assertOk()->assertJsonPath('data.ambiente_fiscal', 'PRODUCAO');

        $this->assertSame(1, Activity::query()->where('event', 'ambiente_producao_confirmado')->count());
    }

    private function criarSerieNfe(int $proximoNumero = 12): EmitenteSerie
    {
        $this->spa()->actingAs($this->admin)->putJson('/api/app/emitente/series/NFE', ['serie' => '1', 'proximo_numero' => $proximoNumero])->assertOk();

        return EmitenteSerie::query()->firstOrFail();
    }

    public function test_confirmar_producao_grava_o_numero_inicial_de_producao_por_serie(): void
    {
        $this->criarSerieNfe(12);
        $this->nfeDeTesteAutorizada();

        $this->spa()->actingAs($this->admin)->postJson('/api/app/emitente/ambiente/producao', ['confirmo' => true, 'numeros_iniciais' => ['NFE' => 500]])
            ->assertOk()->assertJsonPath('data.series.0.proximo_numero', 500);

        $serie = EmitenteSerie::query()->firstOrFail();
        $this->assertSame(500, $serie->proximo_numero_producao);
        $this->assertSame(12, $serie->proximo_numero);
    }

    public function test_confirmar_producao_sem_numero_inicial_comeca_do_1(): void
    {
        $this->criarSerieNfe(12)->update(['proximo_numero_producao' => 77]);
        $this->nfeDeTesteAutorizada();

        $this->spa()->actingAs($this->admin)->postJson('/api/app/emitente/ambiente/producao', ['confirmo' => true])->assertOk();

        $this->assertSame(1, EmitenteSerie::query()->firstOrFail()->proximo_numero_producao);
    }

    public function test_numero_inicial_invalido_e_recusado_e_nada_muda(): void
    {
        $this->criarSerieNfe();
        $this->nfeDeTesteAutorizada();

        foreach ([0, -3, 'abc'] as $ruim) {
            $this->spa()->actingAs($this->admin)->postJson('/api/app/emitente/ambiente/producao', ['confirmo' => true, 'numeros_iniciais' => ['NFE' => $ruim]])
                ->assertStatus(422);
        }
        $this->spa()->actingAs($this->admin)->getJson('/api/app/emitente')->assertJsonPath('data.ambiente_fiscal', 'HOMOLOGACAO');
    }

    public function test_numero_inicial_de_modelo_sem_serie_e_ignorado(): void
    {
        $this->criarSerieNfe();
        $this->nfeDeTesteAutorizada();

        $this->spa()->actingAs($this->admin)->postJson('/api/app/emitente/ambiente/producao', ['confirmo' => true, 'numeros_iniciais' => ['DPS' => 9]])
            ->assertOk();

        $this->assertSame(1, EmitenteSerie::query()->firstOrFail()->proximo_numero_producao);
    }

    public function test_reentrada_nao_reinicia_o_contador_de_producao(): void
    {
        $this->criarSerieNfe();
        $this->nfeDeTesteAutorizada();
        $this->spa()->actingAs($this->admin)->postJson('/api/app/emitente/ambiente/producao', ['confirmo' => true, 'numeros_iniciais' => ['NFE' => 500]])->assertOk();
        $emitente = Emitente::query()->firstOrFail();
        $nota = Nota::factory()->create(['emitente_id' => $emitente->id, 'cliente_id' => Cliente::factory()->create()->id]);
        $nota->forceFill(['status' => StatusNota::Autorizada, 'ambiente' => 'PRODUCAO', 'numero' => 500])->save();
        EmitenteSerie::query()->update(['proximo_numero_producao' => 501]);

        $this->spa()->actingAs($this->admin)->postJson('/api/app/emitente/ambiente/producao', ['confirmo' => true, 'numeros_iniciais' => ['NFE' => 1]])->assertOk();

        $this->assertSame(501, EmitenteSerie::query()->firstOrFail()->proximo_numero_producao);
    }

    public function test_confirmar_producao_sem_emissao_de_teste_bloqueia(): void
    {
        $this->spa()->actingAs($this->admin)->postJson('/api/app/emitente/ambiente/producao', ['confirmo' => true])
            ->assertStatus(422)->assertJsonPath('codigo', 'FISCAL_SEM_EMISSAO_DE_TESTE');
        $this->spa()->actingAs($this->admin)->getJson('/api/app/emitente')->assertJsonPath('data.ambiente_fiscal', 'HOMOLOGACAO')
            ->assertJsonPath('data.emissao_de_teste_autorizada', false);
    }

    public function test_nota_autorizada_em_producao_nao_conta_como_emissao_de_teste(): void
    {
        $this->nfeDeTesteAutorizada();
        Nota::query()->update(['ambiente' => 'PRODUCAO']);
        $this->spa()->actingAs($this->admin)->postJson('/api/app/emitente/ambiente/producao', ['confirmo' => true])->assertStatus(422);
    }

    public function test_resource_indica_emissao_de_teste_autorizada(): void
    {
        $this->nfeDeTesteAutorizada();
        $this->spa()->actingAs($this->admin)->getJson('/api/app/emitente')->assertJsonPath('data.emissao_de_teste_autorizada', true);
    }

    public function test_vendedor_nao_altera_csc_serie_ou_ambiente(): void
    {
        $vendedor = Usuario::factory()->for($this->tenant)->create(['papel' => Papel::Vendedor]);

        $this->spa()->actingAs($vendedor)->putJson('/api/app/emitente/csc', ['csc_id_homologacao' => '1'])->assertForbidden();
        $this->spa()->actingAs($vendedor)->putJson('/api/app/emitente/series/NFE', ['serie' => '1', 'proximo_numero' => 1])->assertForbidden();
        $this->spa()->actingAs($vendedor)->postJson('/api/app/emitente/ambiente/producao', ['confirmo' => true])->assertForbidden();
    }
}

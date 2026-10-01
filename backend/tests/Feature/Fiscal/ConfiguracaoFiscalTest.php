<?php

declare(strict_types=1);

namespace Tests\Feature\Fiscal;

use App\Modules\Identity\Domain\Enums\Papel;
use App\Modules\Identity\Domain\Models\Usuario;
use App\Modules\Platform\Domain\Models\Plano;
use App\Modules\Tenancy\Domain\Models\Tenant;
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

    public function test_confirmar_producao_exige_a_flag_explicita(): void
    {
        $this->spa()->actingAs($this->admin)->postJson('/api/app/emitente/ambiente/producao', [])
            ->assertStatus(422)->assertJsonValidationErrors('confirmo');
    }

    public function test_confirmar_producao_muda_o_ambiente_e_audita(): void
    {
        $this->spa()->actingAs($this->admin)->postJson('/api/app/emitente/ambiente/producao', ['confirmo' => true])
            ->assertOk()->assertJsonPath('data.ambiente_fiscal', 'PRODUCAO');

        $this->assertSame(1, Activity::query()->where('event', 'ambiente_producao_confirmado')->count());
    }

    public function test_vendedor_nao_altera_csc_serie_ou_ambiente(): void
    {
        $vendedor = Usuario::factory()->for($this->tenant)->create(['papel' => Papel::Vendedor]);

        $this->spa()->actingAs($vendedor)->putJson('/api/app/emitente/csc', ['csc_id_homologacao' => '1'])->assertForbidden();
        $this->spa()->actingAs($vendedor)->putJson('/api/app/emitente/series/NFE', ['serie' => '1', 'proximo_numero' => 1])->assertForbidden();
        $this->spa()->actingAs($vendedor)->postJson('/api/app/emitente/ambiente/producao', ['confirmo' => true])->assertForbidden();
    }
}

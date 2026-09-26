<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Modules\Identity\Domain\Enums\Papel;
use App\Modules\Identity\Domain\Models\Usuario;
use App\Modules\Platform\Domain\Models\Plano;
use App\Modules\Tenancy\Domain\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class AdminPlanosTest extends TestCase
{
    use RefreshDatabase;

    private Usuario $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = Usuario::factory()->create(['tenant_id' => null, 'papel' => Papel::Superadmin]);
    }

    /** @param array<string, mixed> $extra @return array<string, mixed> */
    private function payload(array $extra = []): array
    {
        return array_merge([
            'nome' => 'Essencial',
            'descricao' => 'Para quem está começando.',
            'preco_mensal_centavos' => 9900,
            'preco_anual_centavos' => 99000,
            'dias_teste' => 14,
            'politica_excedente' => 'BLOQUEAR',
            'preco_documento_excedente_centavos' => null,
            'ativo' => true,
            'visivel' => true,
            'ordem' => 1,
            'modulos' => ['FISCAL_NFE', 'CRM'],
            'limites' => ['USUARIOS' => 3, 'CLIENTES' => -1],
        ], $extra);
    }

    public function test_superadmin_cria_plano_com_modulos_e_limites(): void
    {
        $resposta = $this->spa()->actingAs($this->admin)->postJson('/api/admin/planos', $this->payload())
            ->assertCreated()
            ->assertJsonPath('data.nome', 'Essencial')
            ->assertJsonPath('data.modulos', ['FISCAL_NFE', 'CRM'])
            ->assertJsonPath('data.limites.USUARIOS', 3)
            ->assertJsonPath('data.limites.CLIENTES', -1)
            ->assertJsonPath('data.limites.PRODUTOS', 0)
            ->assertJsonPath('data.empresas', 0);

        $this->assertDatabaseHas('activity_log', [
            'event' => 'plano_criado', 'subject_id' => $resposta->json('data.id'), 'causer_id' => $this->admin->id,
        ]);
    }

    public function test_editar_substitui_modulos_e_limites_e_informa_quantas_empresas_usam(): void
    {
        $plano = Plano::factory()->create();
        Tenant::factory()->count(2)->for($plano)->create();

        $this->spa()->actingAs($this->admin)
            ->putJson("/api/admin/planos/{$plano->id}", $this->payload(['nome' => $plano->nome, 'modulos' => ['API'], 'limites' => []]))
            ->assertOk()
            ->assertJsonPath('data.modulos', ['API'])
            ->assertJsonPath('data.limites.USUARIOS', 0)
            ->assertJsonPath('data.empresas', 2);

        $this->assertSame(1, Activity::query()->where('event', 'plano_editado')->count());
    }

    public function test_validacoes(): void
    {
        Plano::factory()->create(['nome' => 'Essencial']);
        $admin = $this->spa()->actingAs($this->admin);

        $admin->postJson('/api/admin/planos', $this->payload())->assertJsonValidationErrors('nome');
        $admin->postJson('/api/admin/planos', $this->payload(['nome' => 'B', 'politica_excedente' => 'COBRAR']))
            ->assertJsonValidationErrors('preco_documento_excedente_centavos');
        $admin->postJson('/api/admin/planos', $this->payload(['nome' => 'C', 'limites' => ['INVENTADO' => 1]]))
            ->assertJsonValidationErrors('limites');
        $admin->postJson('/api/admin/planos', $this->payload(['nome' => 'D', 'limites' => ['USUARIOS' => -2]]))
            ->assertJsonValidationErrors('limites.USUARIOS');
        $admin->postJson('/api/admin/planos', $this->payload(['nome' => 'E', 'modulos' => ['NFE']]))
            ->assertJsonValidationErrors('modulos.0');
        $admin->postJson('/api/admin/planos', $this->payload(['nome' => 'F', 'preco_mensal_centavos' => 99.9]))
            ->assertJsonValidationErrors('preco_mensal_centavos');
    }

    public function test_desativar_mantem_as_empresas_no_plano(): void
    {
        $plano = Plano::factory()->create();
        $tenant = Tenant::factory()->for($plano)->create();

        $this->spa()->actingAs($this->admin)->postJson("/api/admin/planos/{$plano->id}/desativar")
            ->assertOk()
            ->assertJsonPath('data.ativo', false);

        $this->assertSame($plano->id, $tenant->refresh()->plano_id);
        $this->assertSame(1, Activity::query()->where('event', 'plano_desativado')->count());
    }

    public function test_nao_existe_exclusao(): void
    {
        $plano = Plano::factory()->create();

        $this->spa()->actingAs($this->admin)->deleteJson("/api/admin/planos/{$plano->id}")->assertStatus(405);
    }

    public function test_suporte_le_mas_nao_escreve(): void
    {
        $suporte = Usuario::factory()->create(['tenant_id' => null, 'papel' => Papel::Suporte]);

        $this->spa()->actingAs($suporte)->getJson('/api/admin/planos')->assertOk();
        $this->spa()->actingAs($suporte)->postJson('/api/admin/planos', $this->payload())->assertForbidden();
    }

    public function test_usuario_de_empresa_nao_acessa(): void
    {
        $dono = Usuario::factory()->for(Tenant::factory())->create();

        $this->spa()->actingAs($dono)->getJson('/api/admin/planos')->assertForbidden();
    }
}

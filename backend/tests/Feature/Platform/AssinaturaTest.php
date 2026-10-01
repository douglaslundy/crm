<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Modules\Identity\Domain\Models\Usuario;
use App\Modules\Platform\Domain\Enums\Modulo;
use App\Modules\Platform\Domain\Enums\Recurso;
use App\Modules\Platform\Domain\Models\Plano;
use App\Modules\Tenancy\Domain\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AssinaturaTest extends TestCase
{
    use RefreshDatabase;

    public function test_devolve_situacao_plano_e_consumo(): void
    {
        $plano = Plano::factory()
            ->comModulos(Modulo::FiscalNfe)
            ->comLimites([Recurso::Usuarios->value => 3, Recurso::Clientes->value => -1])
            ->create(['nome' => 'Essencial']);
        $tenant = Tenant::factory()->for($plano)->emTeste('2026-10-15')->create();
        $usuario = Usuario::factory()->for($tenant)->create();

        $resposta = $this->spa()->actingAs($usuario)->getJson('/api/app/assinatura')
            ->assertOk()
            ->assertJsonPath('data.situacao', 'TESTE')
            ->assertJsonPath('data.teste_termina_em', '2026-10-15')
            ->assertJsonPath('data.plano.nome', 'Essencial')
            ->assertJsonPath('data.plano.modulos', ['FISCAL_NFE'])
            ->assertJsonPath('data.plano.limites.USUARIOS', 3)
            ->assertJsonPath('data.plano.limites.CLIENTES', -1)
            ->assertJsonPath('data.plano.limites.PRODUTOS', 0)
            ->assertJsonMissingPath('data.plano.empresas');

        // consumo() lista todo recurso com contador registrado; outros módulos (F2+) registram os seus.
        $this->assertContains(['recurso' => 'USUARIOS', 'uso' => 1, 'limite' => 3], $resposta->json('data.consumo'));
    }

    public function test_consumo_isola_por_tenant(): void
    {
        $plano = Plano::factory()->comLimites([Recurso::Usuarios->value => 3])->create();
        $tenant = Tenant::factory()->for($plano)->create();
        $usuario = Usuario::factory()->for($tenant)->create();

        $outroPlano = Plano::factory()->comLimites([Recurso::Usuarios->value => 10])->create();
        $outroTenant = Tenant::factory()->for($outroPlano)->create();
        Usuario::factory()->count(4)->for($outroTenant)->create();

        $resposta = $this->spa()->actingAs($usuario)->getJson('/api/app/assinatura')->assertOk();

        $this->assertContains(['recurso' => 'USUARIOS', 'uso' => 1, 'limite' => 3], $resposta->json('data.consumo'));
    }
}

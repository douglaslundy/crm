<?php

declare(strict_types=1);

namespace Tests\Feature\Fiscal;

use App\Modules\Fiscal\Domain\Enums\StatusNota;
use App\Modules\Fiscal\Domain\Models\Nota;
use App\Modules\Identity\Domain\Enums\Papel;
use App\Modules\Identity\Domain\Models\Usuario;
use App\Modules\Platform\Domain\Enums\Modulo;
use App\Modules\Platform\Domain\Models\Plano;
use App\Modules\Tenancy\Domain\Models\Tenant;
use App\Modules\Tenancy\Domain\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\CenarioDeNfse;
use Tests\TestCase;

class NfseIsolamentoTest extends TestCase
{
    use CenarioDeNfse;
    use RefreshDatabase;

    public function test_nota_dps_de_outro_tenant_e_invisivel_em_todas_as_rotas(): void
    {
        $this->montarCenarioNfse();
        $dela = $this->notaDeNfsePronta(StatusNota::Autorizada, ['chave' => str_repeat('1', 50), 'numero_nfse' => '1', 'xml_autorizado' => '<NFSe>a</NFSe>']);

        $outro = Tenant::factory()->for(Plano::factory()->comModulos(Modulo::FiscalNfse))->create();
        $intruso = Usuario::factory()->for($outro)->create(['papel' => Papel::Admin]);
        app(TenantContext::class)->set($outro->id);

        foreach ([['get', ''], ['get', '/status'], ['get', '/xml'], ['get', '/pdf'], ['post', '/emitir'], ['post', '/cancelar']] as [$metodo, $rota]) {
            $this->spaComo($intruso)->json($metodo, "/api/app/notas/{$dela->id}{$rota}", $rota === '/cancelar' ? ['justificativa' => 'Erro na emissão da nota fiscal'] : [])
                ->assertStatus(404);
        }
        $this->assertSame(StatusNota::Autorizada, Nota::withoutTenantScope()->findOrFail($dela->id)->status);
    }
}

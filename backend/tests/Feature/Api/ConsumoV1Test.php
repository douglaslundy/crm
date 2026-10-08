<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Modules\Customers\Domain\Models\Cliente;
use App\Modules\Platform\Application\EntitlementService;
use App\Modules\Platform\Domain\Enums\Recurso;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\CenarioDeApi;
use Tests\TestCase;

class ConsumoV1Test extends TestCase
{
    use CenarioDeApi;
    use RefreshDatabase;

    public function test_uso_e_limite_batem_com_o_entitlement(): void
    {
        $this->montarApi([Recurso::Clientes->value => 7]);
        Cliente::factory()->for($this->tenant)->count(3)->create();

        $resposta = $this->comoApi()->getJson('/api/v1/consumo')->assertOk();

        $esperado = app(EntitlementService::class)->consumo($this->tenant->fresh() ?? $this->tenant);
        $this->assertSame($esperado, $resposta->json('data'));
        $clientes = collect($resposta->json('data'))->firstWhere('recurso', 'CLIENTES');
        $this->assertSame(['recurso' => 'CLIENTES', 'uso' => 3, 'limite' => 7], $clientes);
    }

    public function test_nao_conta_o_uso_de_outro_tenant(): void
    {
        $this->montarApi();
        $this->emOutroTenant(fn ($outro) => Cliente::factory()->for($outro)->count(2)->create());

        $clientes = collect($this->comoApi()->getJson('/api/v1/consumo')->json('data'))->firstWhere('recurso', 'CLIENTES');

        $this->assertSame(0, $clientes['uso']);
    }
}

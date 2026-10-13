<?php

declare(strict_types=1);

namespace Tests\Feature\Crm;

use App\Modules\Crm\Domain\Enums\UnidadeDaMeta;
use App\Modules\Crm\Domain\Models\MetaDoVendedor;
use App\Modules\Tenancy\Domain\Models\Tenant;
use App\Modules\Tenancy\Domain\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ComCrm;
use Tests\TestCase;

class MetasModeloTest extends TestCase
{
    use ComCrm;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararCrm();
    }

    public function test_guarda_mes_como_data_e_unidade_como_enum(): void
    {
        $meta = MetaDoVendedor::factory()->create([
            'usuario_id' => $this->admin->id, 'mes' => '2026-10-01', 'unidade' => UnidadeDaMeta::Valor, 'alvo' => 500000,
        ])->refresh();

        $this->assertSame('2026-10-01', $meta->mes->toDateString());
        $this->assertSame(UnidadeDaMeta::Valor, $meta->unidade);
        $this->assertSame(500000, $meta->alvo);
    }

    public function test_so_enxerga_metas_do_proprio_tenant(): void
    {
        MetaDoVendedor::factory()->create(['usuario_id' => $this->admin->id]);
        $outro = Tenant::factory()->create();
        app(TenantContext::class)->set($outro->id);

        $this->assertSame(0, MetaDoVendedor::query()->count());
    }

    public function test_uma_meta_por_vendedor_e_mes(): void
    {
        MetaDoVendedor::factory()->create(['usuario_id' => $this->admin->id, 'mes' => '2026-10-01']);

        $this->expectException(QueryException::class);
        MetaDoVendedor::factory()->create(['usuario_id' => $this->admin->id, 'mes' => '2026-10-01']);
    }
}

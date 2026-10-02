<?php

declare(strict_types=1);

namespace Tests\Feature\Fiscal;

use App\Modules\Fiscal\Domain\Models\Nota;
use App\Modules\Fiscal\Domain\Models\NotaEvento;
use App\Modules\Fiscal\Domain\Models\NotaItem;
use App\Modules\Fiscal\Domain\Models\NotaPagamento;
use App\Modules\Tenancy\Domain\Models\Tenant;
use App\Modules\Tenancy\Domain\TenantContext;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NotaIsolamentoTest extends TestCase
{
    use RefreshDatabase;

    public function test_nota_e_isolada_por_tenant(): void
    {
        $this->verificaIsolamento(fn () => Nota::factory()->create());
    }

    public function test_item_e_isolado_por_tenant(): void
    {
        $this->verificaIsolamento(fn () => NotaItem::factory()->create());
    }

    public function test_pagamento_e_isolado_por_tenant(): void
    {
        $this->verificaIsolamento(fn () => NotaPagamento::query()->create([
            'nota_id' => Nota::factory()->create()->id, 'tpag' => '01', 'valor_centavos' => 100,
        ]));
    }

    public function test_evento_e_isolado_por_tenant(): void
    {
        $this->verificaIsolamento(fn () => NotaEvento::query()->create([
            'nota_id' => Nota::factory()->create()->id, 'status_para' => 'RASCUNHO',
        ]));
    }

    private function verificaIsolamento(Closure $criar): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        $contexto = app(TenantContext::class);

        $contexto->set($tenantA->id);
        /** @var Model $registro */
        $registro = $criar();
        $this->assertSame($tenantA->id, $registro->getAttribute('tenant_id'));

        $contexto->set($tenantB->id);
        $this->assertSame(0, $registro::query()->count());
    }
}

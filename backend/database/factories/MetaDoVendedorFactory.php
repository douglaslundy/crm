<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Crm\Domain\Enums\UnidadeDaMeta;
use App\Modules\Crm\Domain\Models\MetaDoVendedor;
use App\Modules\Identity\Domain\Models\Usuario;
use App\Modules\Tenancy\Domain\TenantContext;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<MetaDoVendedor> */
class MetaDoVendedorFactory extends Factory
{
    protected $model = MetaDoVendedor::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'usuario_id' => fn (): string => Usuario::factory()->create(['tenant_id' => app(TenantContext::class)->require()])->id,
            'mes' => now()->startOfMonth()->toDateString(),
            'unidade' => UnidadeDaMeta::Valor,
            'alvo' => 100000,
        ];
    }
}

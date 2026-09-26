<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Identity\Domain\Enums\Papel;
use App\Modules\Identity\Domain\Models\Usuario;
use App\Modules\Tenancy\Domain\Models\Tenant;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /** Dados de demonstração só para o ambiente local. */
    public function run(): void
    {
        if (! app()->environment('local')) {
            return;
        }

        Usuario::factory()->create([
            'nome' => 'Admin da Plataforma', 'email' => 'admin@plataforma.local',
            'papel' => Papel::Superadmin, 'tenant_id' => null,
        ]);

        $tenant = Tenant::factory()->create(['nome' => 'Empresa Demonstração', 'cnpj' => '11222333000181']);

        Usuario::factory()->create([
            'nome' => 'Dono da Empresa', 'email' => 'dono@empresa.local',
            'papel' => Papel::Proprietario, 'tenant_id' => $tenant->id,
        ]);
    }
}

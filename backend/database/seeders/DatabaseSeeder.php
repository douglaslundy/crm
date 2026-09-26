<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Identity\Domain\Enums\Papel;
use App\Modules\Identity\Domain\Models\Usuario;
use App\Modules\Platform\Domain\Enums\Modulo;
use App\Modules\Platform\Domain\Enums\Recurso;
use App\Modules\Platform\Domain\Models\Plano;
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

        $essencial = Plano::factory()
            ->comModulos(Modulo::FiscalNfe, Modulo::FiscalNfce)
            ->comLimites([Recurso::Usuarios->value => 3, Recurso::Clientes->value => 500, Recurso::Produtos->value => 500, Recurso::DocumentosMes->value => 200])
            ->create(['nome' => 'Essencial', 'preco_mensal_centavos' => 9900, 'dias_teste' => 14, 'ordem' => 1]);

        Plano::factory()
            ->comModulos(...Modulo::cases())
            ->comLimites([
                Recurso::Usuarios->value => 10, Recurso::Clientes->value => -1, Recurso::Produtos->value => -1,
                Recurso::Servicos->value => -1, Recurso::DocumentosMes->value => 2000, Recurso::ApiRequisicoesMin->value => 120,
            ])
            ->create(['nome' => 'Profissional', 'preco_mensal_centavos' => 24900, 'dias_teste' => 14, 'ordem' => 2]);

        $tenant = Tenant::factory()->create([
            'razao_social' => 'Empresa Demonstração Ltda', 'nome_fantasia' => 'Empresa Demonstração',
            'cnpj' => '11222333000181', 'plano_id' => $essencial->id,
        ]);

        Usuario::factory()->create([
            'nome' => 'Dono da Empresa', 'email' => 'dono@empresa.local',
            'papel' => Papel::Proprietario, 'tenant_id' => $tenant->id,
        ]);
    }
}

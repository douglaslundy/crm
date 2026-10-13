<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Crm\Domain\Enums\StatusDoNegocio;
use App\Modules\Crm\Domain\Models\Etapa;
use App\Modules\Crm\Domain\Models\Funil;
use App\Modules\Crm\Domain\Models\Negocio;
use App\Modules\Customers\Domain\Models\Cliente;
use App\Modules\Identity\Domain\Models\Usuario;
use App\Modules\Tenancy\Domain\TenantContext;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Negocio> */
class NegocioFactory extends Factory
{
    protected $model = Negocio::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'titulo' => fake()->sentence(3),
            'cliente_id' => fn (): string => Cliente::factory()->create()->id,
            'funil_id' => Funil::factory(),
            'etapa_id' => fn (array $atributos): string => Etapa::factory()->create(['funil_id' => $atributos['funil_id']])->id,
            'responsavel_id' => fn (): string => Usuario::factory()
                ->create(['tenant_id' => app(TenantContext::class)->require()])->id,
            'status' => StatusDoNegocio::Aberto,
            'valor_centavos' => 0,
            'posicao_na_etapa' => 0,
        ];
    }
}

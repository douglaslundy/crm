<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Crm\Domain\Enums\TipoDeAtividade;
use App\Modules\Crm\Domain\Models\Atividade;
use App\Modules\Customers\Domain\Models\Cliente;
use App\Modules\Identity\Domain\Models\Usuario;
use App\Modules\Tenancy\Domain\TenantContext;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Atividade> */
class AtividadeFactory extends Factory
{
    protected $model = Atividade::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'cliente_id' => fn (): string => Cliente::factory()->create()->id,
            'tipo' => TipoDeAtividade::Tarefa,
            'titulo' => fake()->sentence(3),
            'agendada_para' => now()->addDay(),
            'responsavel_id' => fn (): string => Usuario::factory()
                ->create(['tenant_id' => app(TenantContext::class)->require()])->id,
            'criado_por' => fn (array $atributos): string => $atributos['responsavel_id'],
        ];
    }

    public function concluida(): static
    {
        return $this->state(fn (): array => ['concluida_em' => now()]);
    }
}

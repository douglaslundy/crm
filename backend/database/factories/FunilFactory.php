<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Crm\Domain\Models\Funil;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Funil> */
class FunilFactory extends Factory
{
    protected $model = Funil::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return ['nome' => fake()->words(2, true), 'padrao' => false, 'ativo' => true, 'posicao' => 0];
    }

    public function padrao(): static
    {
        return $this->state(fn (): array => ['padrao' => true, 'nome' => 'Vendas']);
    }
}

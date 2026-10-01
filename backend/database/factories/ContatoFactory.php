<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Customers\Domain\Models\Contato;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Contato> */
class ContatoFactory extends Factory
{
    protected $model = Contato::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'nome' => fake()->name(),
            'cargo' => null,
            'email' => fake()->unique()->safeEmail(),
            'telefone' => fake()->numerify('###########'),
        ];
    }
}

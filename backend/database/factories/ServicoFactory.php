<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Catalog\Domain\Models\Servico;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Servico> */
class ServicoFactory extends Factory
{
    protected $model = Servico::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'nome' => fake()->words(3, true),
            'preco_centavos' => fake()->numberBetween(1000, 100000),
            'codigo_lc116' => null,
            'c_trib_nac' => null,
            'codigo_municipal' => null,
            'aliquota_iss' => null,
            'nbs' => null,
        ];
    }

    public function completo(): static
    {
        return $this->state(fn (): array => [
            'codigo_lc116' => '14.01',
            'c_trib_nac' => '140101',
            'aliquota_iss' => '5.00',
        ]);
    }
}

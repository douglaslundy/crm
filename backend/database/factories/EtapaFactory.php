<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Crm\Domain\Models\Etapa;
use App\Modules\Crm\Domain\Models\Funil;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Etapa> */
class EtapaFactory extends Factory
{
    protected $model = Etapa::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'funil_id' => Funil::factory(),
            'nome' => fake()->words(2, true),
            'posicao' => 0,
            'probabilidade' => 10,
            'ativa' => true,
        ];
    }
}

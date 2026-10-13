<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Crm\Domain\Models\MotivoDePerda;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<MotivoDePerda> */
class MotivoDePerdaFactory extends Factory
{
    protected $model = MotivoDePerda::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return ['nome' => fake()->words(2, true), 'ativo' => true];
    }
}

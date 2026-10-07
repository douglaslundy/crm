<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Customers\Domain\Models\Cliente;
use App\Modules\Fiscal\Domain\Models\Venda;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Venda> */
class VendaFactory extends Factory
{
    protected $model = Venda::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return ['cliente_id' => Cliente::factory()];
    }
}

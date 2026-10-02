<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Customers\Domain\Models\Cliente;
use App\Modules\Fiscal\Domain\Models\Emitente;
use App\Modules\Fiscal\Domain\Models\Nota;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Nota> */
class NotaFactory extends Factory
{
    protected $model = Nota::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'emitente_id' => Emitente::factory(),
            'cliente_id' => Cliente::factory(),
        ];
    }
}

<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Fiscal\Domain\Enums\ModeloDocumento;
use App\Modules\Fiscal\Domain\Models\EmitenteSerie;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<EmitenteSerie> */
class EmitenteSerieFactory extends Factory
{
    protected $model = EmitenteSerie::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'modelo' => ModeloDocumento::Nfe,
            'serie' => '1',
            'proximo_numero' => 1,
        ];
    }
}

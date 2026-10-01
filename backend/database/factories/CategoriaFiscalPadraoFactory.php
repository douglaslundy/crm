<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Catalog\Domain\Enums\TributacaoIcms;
use App\Modules\Catalog\Domain\Models\CategoriaFiscalPadrao;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CategoriaFiscalPadrao> */
class CategoriaFiscalPadraoFactory extends Factory
{
    protected $model = CategoriaFiscalPadrao::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'categoria' => 'Categoria '.fake()->unique()->numerify('##'),
            'ncm' => '12345678',
            'origem' => 0,
            'tributacao_icms' => TributacaoIcms::Normal,
        ];
    }
}

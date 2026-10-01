<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Catalog\Domain\Enums\FonteFiscal;
use App\Modules\Catalog\Domain\Enums\TributacaoIcms;
use App\Modules\Catalog\Domain\Models\Produto;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Produto> */
class ProdutoFactory extends Factory
{
    protected $model = Produto::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'sku' => strtoupper(fake()->unique()->bothify('SKU-####')),
            'nome' => fake()->words(3, true),
            'unidade' => 'UN',
            'preco_centavos' => fake()->numberBetween(1000, 100000),
            'gtin' => null,
            'ncm' => null,
            'cest' => null,
            'origem' => null,
            'tributacao_icms' => null,
            'fiscal_fonte' => FonteFiscal::Manual,
            'fiscal_revisado_em' => null,
        ];
    }

    public function completo(): static
    {
        return $this->state(fn (): array => [
            'ncm' => '12345678',
            'origem' => 0,
            'tributacao_icms' => TributacaoIcms::Normal,
        ]);
    }
}

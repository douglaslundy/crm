<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Catalog\Domain\Models\Produto;
use App\Modules\Fiscal\Domain\Models\Nota;
use App\Modules\Fiscal\Domain\Models\NotaItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<NotaItem> */
class NotaItemFactory extends Factory
{
    protected $model = NotaItem::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'nota_id' => Nota::factory(),
            'produto_id' => Produto::factory(),
            'ordem' => 1,
            'quantidade_milesimos' => 1000,
            'valor_unitario_centavos' => 1000,
            'total_centavos' => 1000,
        ];
    }
}

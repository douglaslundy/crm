<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Billing\Domain\Enums\CicloDeCobranca;
use App\Modules\Billing\Domain\Enums\MetodoDePagamento;
use App\Modules\Billing\Domain\Enums\StatusDaAssinatura;
use App\Modules\Billing\Domain\Models\Assinatura;
use App\Modules\Platform\Domain\Models\Plano;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Assinatura> */
class AssinaturaFactory extends Factory
{
    protected $model = Assinatura::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'plano_id' => Plano::factory(),
            'ciclo' => CicloDeCobranca::Mensal,
            'metodo' => MetodoDePagamento::Boleto,
            'situacao' => StatusDaAssinatura::Pendente,
        ];
    }
}

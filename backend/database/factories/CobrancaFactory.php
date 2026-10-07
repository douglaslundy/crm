<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Billing\Domain\Enums\MetodoDePagamento;
use App\Modules\Billing\Domain\Enums\StatusDaCobranca;
use App\Modules\Billing\Domain\Enums\TipoDeCobranca;
use App\Modules\Billing\Domain\Models\Assinatura;
use App\Modules\Billing\Domain\Models\Cobranca;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Cobranca> */
class CobrancaFactory extends Factory
{
    protected $model = Cobranca::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'assinatura_id' => Assinatura::factory(),
            'tipo' => TipoDeCobranca::Assinatura,
            'valor_centavos' => 10000,
            'vence_em' => now()->toDateString(),
            'status' => StatusDaCobranca::Pendente,
            'metodo' => MetodoDePagamento::Boleto,
        ];
    }
}

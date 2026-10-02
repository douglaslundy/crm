<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Fiscal\Domain\Enums\StatusInutilizacao;
use App\Modules\Fiscal\Domain\Models\Emitente;
use App\Modules\Fiscal\Domain\Models\Inutilizacao;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Inutilizacao> */
class InutilizacaoFactory extends Factory
{
    protected $model = Inutilizacao::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'emitente_id' => Emitente::factory(),
            'serie' => '1',
            'numero_inicial' => 1,
            'numero_final' => 1,
            'justificativa' => 'Numeração perdida por falha de comunicação',
            'ambiente' => 'HOMOLOGACAO',
            'status' => StatusInutilizacao::Inutilizada,
        ];
    }
}

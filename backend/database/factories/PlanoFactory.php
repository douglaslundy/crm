<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Platform\Domain\Enums\Modulo;
use App\Modules\Platform\Domain\Enums\PoliticaExcedente;
use App\Modules\Platform\Domain\Models\Plano;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Plano> */
class PlanoFactory extends Factory
{
    protected $model = Plano::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'nome' => 'Plano '.fake()->unique()->numerify('#####'),
            'descricao' => null,
            'preco_mensal_centavos' => 9900,
            'preco_anual_centavos' => null,
            'dias_teste' => 0,
            'politica_excedente' => PoliticaExcedente::Bloquear,
            'preco_documento_excedente_centavos' => null,
            'ativo' => true,
            'visivel' => true,
            'ordem' => 0,
        ];
    }

    /** @param array<string, int> $limites chave = valor de Recurso */
    public function comLimites(array $limites): static
    {
        return $this->afterCreating(function (Plano $plano) use ($limites): void {
            foreach ($limites as $recurso => $limite) {
                $plano->limites()->create(['recurso' => $recurso, 'limite' => $limite]);
            }
            $plano->unsetRelation('limites');
        });
    }

    public function comModulos(Modulo ...$modulos): static
    {
        return $this->afterCreating(function (Plano $plano) use ($modulos): void {
            foreach ($modulos as $modulo) {
                $plano->modulos()->create(['modulo' => $modulo]);
            }
            $plano->unsetRelation('modulos');
        });
    }
}

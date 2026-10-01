<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Customers\Domain\Enums\EstagioCliente;
use App\Modules\Customers\Domain\Enums\TipoCliente;
use App\Modules\Customers\Domain\Models\Cliente;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Cliente> */
class ClienteFactory extends Factory
{
    protected $model = Cliente::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'tipo' => TipoCliente::Pf,
            'nome' => fake()->name(),
            'cpf_cnpj' => null,
            'inscricao_estadual' => null,
            'ie_isento' => false,
            'email' => fake()->unique()->safeEmail(),
            'telefone' => fake()->numerify('###########'),
            'logradouro' => null,
            'numero' => null,
            'bairro' => null,
            'cidade' => null,
            'uf' => null,
            'cep' => null,
            'codigo_ibge' => null,
            'tags' => [],
            'origem' => null,
            'estagio' => EstagioCliente::Lead,
        ];
    }

    public function cliente(): static
    {
        return $this->state(fn (): array => ['estagio' => EstagioCliente::Cliente, 'cpf_cnpj' => self::cpfValido()]);
    }

    public function comCpfValido(): static
    {
        return $this->state(fn (): array => ['tipo' => TipoCliente::Pf, 'cpf_cnpj' => self::cpfValido()]);
    }

    /** Gera um CPF numericamente válido (mesmo algoritmo de `App\Modules\Shared\Domain\Cpf`), só para dado de teste. */
    private static function cpfValido(): string
    {
        $n = [];
        for ($i = 0; $i < 9; $i++) {
            $n[] = random_int(0, 9);
        }
        $n[] = self::digito($n, [10, 9, 8, 7, 6, 5, 4, 3, 2]);
        $n[] = self::digito($n, [11, 10, 9, 8, 7, 6, 5, 4, 3, 2]);

        return implode('', $n);
    }

    /**
     * @param  list<int>  $digitos
     * @param  list<int>  $pesos
     */
    private static function digito(array $digitos, array $pesos): int
    {
        $soma = 0;
        foreach ($pesos as $i => $peso) {
            $soma += $digitos[$i] * $peso;
        }
        $resto = $soma % 11;

        return $resto < 2 ? 0 : 11 - $resto;
    }
}

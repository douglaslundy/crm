<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Platform\Domain\Models\Plano;
use App\Modules\Tenancy\Domain\Enums\SituacaoAssinatura;
use App\Modules\Tenancy\Domain\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Tenant> */
class TenantFactory extends Factory
{
    protected $model = Tenant::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'razao_social' => fake()->company(),
            'nome_fantasia' => null,
            'cnpj' => fake()->unique()->numerify('##############'),
            'plano_id' => Plano::factory(),
            'situacao' => SituacaoAssinatura::Ativa,
            'teste_termina_em' => null,
        ];
    }

    public function situacao(SituacaoAssinatura $situacao): static
    {
        return $this->state(['situacao' => $situacao]);
    }

    public function emTeste(string $terminaEm): static
    {
        return $this->state(['situacao' => SituacaoAssinatura::Teste, 'teste_termina_em' => $terminaEm]);
    }
}

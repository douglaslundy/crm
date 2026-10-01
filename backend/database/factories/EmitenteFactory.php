<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Fiscal\Domain\Enums\AmbienteFiscal;
use App\Modules\Fiscal\Domain\Enums\CertificadoStatus;
use App\Modules\Fiscal\Domain\Models\Emitente;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Emitente> */
class EmitenteFactory extends Factory
{
    protected $model = Emitente::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'ambiente_fiscal' => AmbienteFiscal::Homologacao,
            'certificado_status' => CertificadoStatus::Pendente,
        ];
    }

    public function completo(): static
    {
        return $this->state(fn (): array => [
            'logradouro' => 'Rua Teste', 'numero' => '100', 'bairro' => 'Centro', 'cidade' => 'São Paulo',
            'uf' => 'SP', 'cep' => '01001000', 'codigo_ibge' => '3550308',
            'regime_tributario' => 'SIMPLES', 'inscricao_estadual' => 'ISENTO', 'cnae' => '4520001',
        ]);
    }
}

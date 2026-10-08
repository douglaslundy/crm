<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Identity\Domain\Enums\AmbienteDaChave;
use App\Modules\Identity\Domain\GeradorDeChaveDeApi;
use App\Modules\Identity\Domain\Models\ChaveDeApi;
use App\Modules\Identity\Domain\Models\Usuario;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ChaveDeApi> */
class ChaveDeApiFactory extends Factory
{
    protected $model = ChaveDeApi::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $chave = (new GeradorDeChaveDeApi)->gerar(AmbienteDaChave::Live);

        return [
            'usuario_id' => Usuario::factory(),
            'nome' => 'Integração',
            'ambiente' => AmbienteDaChave::Live,
            'prefixo' => $chave->prefixo,
            'ultimos4' => $chave->ultimos4,
            'hash' => $chave->hash,
        ];
    }
}

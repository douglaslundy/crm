<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Fiscal\Domain\Enums\AmbienteFiscal;
use App\Modules\Fiscal\Domain\Models\WebhookEndpoint;
use App\Modules\Identity\Domain\Models\Usuario;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<WebhookEndpoint> */
class WebhookEndpointFactory extends Factory
{
    protected $model = WebhookEndpoint::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'usuario_id' => Usuario::factory(),
            'url' => 'https://exemplo.test/hook',
            'descricao' => null,
            'eventos' => [],
            'ambiente' => AmbienteFiscal::Homologacao,
            'segredo' => 'whsec_'.Str::random(40),
            'ativo' => true,
            'falhas_seguidas' => 0,
        ];
    }
}

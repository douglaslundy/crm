<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Fiscal\Domain\Enums\EstadoDaEntrega;
use App\Modules\Fiscal\Domain\Models\WebhookEndpoint;
use App\Modules\Fiscal\Domain\Models\WebhookEntrega;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<WebhookEntrega> */
class WebhookEntregaFactory extends Factory
{
    protected $model = WebhookEntrega::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'webhook_endpoint_id' => WebhookEndpoint::factory(),
            'nota_evento_id' => null,
            'nota_id' => null,
            'evento' => 'webhook.teste',
            'evento_id' => (string) Str::uuid(),
            'payload' => ['id' => 'x', 'tipo' => 'webhook.teste'],
            'estado' => EstadoDaEntrega::Pendente,
            'tentativas' => 0,
            'tentativas_na_rodada' => 0,
            'proxima_tentativa_em' => now(),
        ];
    }
}

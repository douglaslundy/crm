<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Application\Webhooks\Actions;

use App\Modules\Fiscal\Application\Webhooks\PoliticaDeWebhooks;
use App\Modules\Fiscal\Application\Webhooks\ResultadoDoWebhook;
use App\Modules\Fiscal\Application\Webhooks\UrlDeWebhookSegura;
use App\Modules\Fiscal\Application\Webhooks\ValidadorDeWebhook;
use App\Modules\Fiscal\Domain\Exceptions\WebhookLimiteAtingidoException;
use App\Modules\Fiscal\Domain\Models\WebhookEndpoint;
use App\Modules\Identity\Domain\Models\Usuario;
use App\Modules\Platform\Application\EntitlementService;
use App\Modules\Platform\Domain\Enums\Modulo;
use App\Modules\Tenancy\Domain\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class CriarWebhook
{
    public function __construct(
        private readonly PoliticaDeWebhooks $politica,
        private readonly EntitlementService $entitlement,
        private readonly ValidadorDeWebhook $validador,
        private readonly UrlDeWebhookSegura $urlSegura,
    ) {}

    /** @param array<string, mixed> $dados url, ambiente, descricao?, eventos? */
    public function executar(Usuario $autor, array $dados): ResultadoDoWebhook
    {
        $this->politica->garantirPodeGerenciar($autor);
        $this->entitlement->garantirModulo($autor->tenant, Modulo::Api);

        $dados = $this->validador->validar($dados, false);
        $this->urlSegura->validar((string) $dados['url']);

        return DB::transaction(function () use ($autor, $dados): ResultadoDoWebhook {
            // Trava a empresa para que duas criações simultâneas não passem do limite.
            Tenant::query()->whereKey($autor->tenant_id)->lockForUpdate()->first();

            if (WebhookEndpoint::query()->count() >= PoliticaDeWebhooks::LIMITE_DE_ENDPOINTS) {
                throw new WebhookLimiteAtingidoException(PoliticaDeWebhooks::LIMITE_DE_ENDPOINTS);
            }

            $segredo = 'whsec_'.Str::random(40);
            $endpoint = new WebhookEndpoint;
            $endpoint->forceFill([
                'usuario_id' => $autor->id,
                'url' => $dados['url'],
                'descricao' => $dados['descricao'] ?? null,
                'eventos' => array_values($dados['eventos'] ?? []),
                'ambiente' => $dados['ambiente'],
                'segredo' => $segredo,
                'ativo' => true,
                'falhas_seguidas' => 0,
            ])->save();

            // Nunca o segredo: só o que identifica o endpoint.
            activity('webhooks')->performedOn($endpoint)->causedBy($autor)->event('webhook_criado')
                ->withProperties(['url' => $endpoint->url, 'ambiente' => $endpoint->ambiente->value, 'eventos' => $endpoint->eventos])
                ->log('Webhook criado');

            return new ResultadoDoWebhook($endpoint, $segredo);
        });
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Application\Webhooks\Actions;

use App\Modules\Fiscal\Application\Webhooks\PoliticaDeWebhooks;
use App\Modules\Fiscal\Application\Webhooks\UrlDeWebhookSegura;
use App\Modules\Fiscal\Application\Webhooks\ValidadorDeWebhook;
use App\Modules\Fiscal\Domain\Models\WebhookEndpoint;
use App\Modules\Identity\Domain\Models\Usuario;
use App\Modules\Platform\Application\EntitlementService;
use App\Modules\Platform\Domain\Enums\Modulo;
use Illuminate\Support\Facades\DB;

final class AtualizarWebhook
{
    public function __construct(
        private readonly PoliticaDeWebhooks $politica,
        private readonly EntitlementService $entitlement,
        private readonly ValidadorDeWebhook $validador,
        private readonly UrlDeWebhookSegura $urlSegura,
    ) {}

    /**
     * Altera só o que veio. O segredo nunca muda aqui.
     *
     * @param  array<string, mixed>  $dados  url, descricao, eventos, ambiente, ativo
     */
    public function executar(Usuario $autor, string $id, array $dados): WebhookEndpoint
    {
        $this->politica->garantirPodeGerenciar($autor);
        $this->entitlement->garantirModulo($autor->tenant, Modulo::Api);

        $validados = $this->validador->validar($dados, true);
        $ativo = array_key_exists('ativo', $validados) ? (bool) $validados['ativo'] : null;
        if (isset($validados['url'])) {
            $this->urlSegura->validar((string) $validados['url']);
        }

        return DB::transaction(function () use ($autor, $id, $validados, $ativo): WebhookEndpoint {
            $endpoint = WebhookEndpoint::query()->lockForUpdate()->findOrFail($id);

            $mudancas = [];
            foreach (['url', 'descricao', 'ambiente'] as $campo) {
                if (array_key_exists($campo, $validados)) {
                    $mudancas[$campo] = $validados[$campo];
                }
            }
            if (array_key_exists('eventos', $validados)) {
                $mudancas['eventos'] = array_values($validados['eventos'] ?? []);
            }
            if ($ativo === true && ! $endpoint->ativo) {
                $mudancas += ['ativo' => true, 'falhas_seguidas' => 0, 'desativado_em' => null, 'motivo_desativacao' => null];
            } elseif ($ativo === false && $endpoint->ativo) {
                $mudancas += ['ativo' => false, 'desativado_em' => now(), 'motivo_desativacao' => 'Desativado manualmente.'];
            }

            $endpoint->forceFill($mudancas)->save();

            activity('webhooks')->performedOn($endpoint)->causedBy($autor)->event('webhook_alterado')
                ->withProperties(['campos' => array_keys($mudancas), 'url' => $endpoint->url, 'ativo' => $endpoint->ativo])
                ->log('Webhook alterado');

            return $endpoint;
        });
    }
}

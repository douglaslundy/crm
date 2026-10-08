<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Application\Webhooks;

use App\Modules\Fiscal\Domain\Enums\EstadoDaEntrega;
use App\Modules\Fiscal\Domain\Enums\EventoDeWebhook;
use App\Modules\Fiscal\Domain\Exceptions\WebhookUrlInvalidaException;
use App\Modules\Fiscal\Domain\Models\WebhookEndpoint;
use App\Modules\Fiscal\Domain\Models\WebhookEntrega;
use App\Modules\Fiscal\Domain\Models\WebhookTentativa;
use App\Modules\Platform\Application\EntitlementService;
use App\Modules\Platform\Domain\Enums\Modulo;
use App\Modules\Tenancy\Domain\Models\Tenant;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Uma tentativa de envio de uma entrega: valida a URL, assina, envia e grava o resultado. O agendamento das
 * próximas tentativas fica na própria entrega (`proxima_tentativa_em`), lido pelo comando `webhooks:reenviar-pendentes`.
 * Roda com o TenantContext da empresa da entrega.
 */
final class EntregadorDeWebhook
{
    /** Espera, em segundos, depois da 1ª, 2ª... 6ª falha da rodada; a 7ª encerra. */
    public const INTERVALOS = [60, 300, 1800, 7200, 21600, 43200];

    public const TENTATIVAS_POR_RODADA = 7;

    public const FALHAS_PARA_DESATIVAR = 10;

    /** Se o worker morrer depois de reservar, a entrega volta a ser elegível depois disto. */
    public const RESERVA_EM_SEGUNDOS = 300;

    private const TIMEOUT_EM_SEGUNDOS = 10;

    private const RESPOSTA_MAXIMA_EM_BYTES = 1024;

    private const RESPOSTA_LIMITE_EM_BYTES = 262144;

    private const ERRO_MAXIMO = 500;

    public function __construct(
        private readonly UrlDeWebhookSegura $urlSegura,
        private readonly AssinadorDeWebhook $assinador,
        private readonly EntitlementService $entitlement,
    ) {}

    public function enviar(string $entregaId): void
    {
        // Quem ganha a reserva já conta a tentativa: se o worker morrer no meio, ela não se perde.
        if (! $this->reservar($entregaId)) {
            return;
        }

        $entrega = WebhookEntrega::query()->find($entregaId);
        if ($entrega === null) {
            return;
        }

        $endpoint = WebhookEndpoint::query()->find($entrega->webhook_endpoint_id);
        $motivo = $this->motivoParaDescartar($entrega, $endpoint);
        if ($endpoint === null || $motivo !== null) {
            $this->descartar($entrega, $motivo ?? 'O webhook não existe mais.');

            return;
        }

        $this->tentar($entrega, $endpoint);
    }

    /**
     * Opções do cliente HTTP: sem redirecionamento (um 3xx é falha) e conexão direta aos IPs já validados, para o DNS
     * não poder mudar entre a validação e a conexão. Para IP literal não há o que fixar.
     *
     * @return array<string, mixed>
     */
    public function opcoesDeConexao(ValidacaoDeUrl $url): array
    {
        $opcoes = [
            'allow_redirects' => false,
            // O proxy do ambiente resolveria o nome por conta própria e anularia a fixação do IP.
            'proxy' => '',
            // Resposta enorme de um destino malicioso não pode esgotar a memória do worker.
            'progress' => static function (int $baixarTotal, int $baixado): void {
                if ($baixado > self::RESPOSTA_LIMITE_EM_BYTES) {
                    throw new RespostaGrandeDemaisException;
                }
            },
        ];
        if (filter_var($url->host, FILTER_VALIDATE_IP) === false && ! str_starts_with($url->host, '[')) {
            $enderecos = array_map(fn (string $ip): string => str_contains($ip, ':') ? "[{$ip}]" : $ip, $url->ips);
            $opcoes['curl'] = [CURLOPT_RESOLVE => ["{$url->host}:{$url->porta}:".implode(',', $enderecos)]];
        }

        return $opcoes;
    }

    /**
     * UPDATE condicional: só uma execução ganha a entrega vencida. Ele trava a entrega por alguns minutos e já conta
     * a tentativa; se o worker morrer, a próxima reserva (pelo comando) encontra o contador andado e a rodada acaba.
     */
    private function reservar(string $entregaId): bool
    {
        $esgotada = WebhookEntrega::query()->whereKey($entregaId)
            ->where('estado', EstadoDaEntrega::Pendente->value)
            ->where('proxima_tentativa_em', '<=', now())
            ->where('tentativas_na_rodada', '>=', self::TENTATIVAS_POR_RODADA)
            ->update(['estado' => EstadoDaEntrega::Falhou->value, 'proxima_tentativa_em' => null]);
        if ($esgotada === 1) {
            return false;
        }

        return WebhookEntrega::query()
            ->whereKey($entregaId)
            ->where('estado', EstadoDaEntrega::Pendente->value)
            ->where('proxima_tentativa_em', '<=', now())
            ->update([
                'proxima_tentativa_em' => now()->addSeconds(self::RESERVA_EM_SEGUNDOS),
                'tentativas' => DB::raw('tentativas + 1'),
                'tentativas_na_rodada' => DB::raw('tentativas_na_rodada + 1'),
            ]) === 1;
    }

    private function motivoParaDescartar(WebhookEntrega $entrega, ?WebhookEndpoint $endpoint): ?string
    {
        if ($endpoint === null) {
            return 'O webhook não existe mais.';
        }
        if (! $endpoint->ativo) {
            return 'O webhook está desativado.';
        }
        if (($entrega->payload['ambiente'] ?? null) !== $endpoint->ambiente->value) {
            return 'O ambiente do webhook não coincide com o do evento.';
        }
        $tenant = Tenant::query()->find($entrega->tenant_id);
        if ($tenant === null || ! $this->entitlement->temModulo($tenant, Modulo::Api)) {
            return 'O plano da empresa não inclui a API.';
        }

        return null;
    }

    private function descartar(WebhookEntrega $entrega, string $motivo): void
    {
        DB::transaction(function () use ($entrega, $motivo): void {
            $this->registrar($entrega, null, 0, $motivo);
            $entrega->forceFill(['estado' => EstadoDaEntrega::Falhou, 'proxima_tentativa_em' => null])->save();
        });
    }

    private function tentar(WebhookEntrega $entrega, WebhookEndpoint $endpoint): void
    {
        $corpo = (string) json_encode($entrega->payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $inicio = microtime(true);
        $resposta = null;
        $erro = null;

        try {
            $url = $this->urlSegura->validar($endpoint->url);
            $t = now()->getTimestamp();
            $resposta = Http::withBody($corpo, 'application/json')
                ->withHeaders([
                    'User-Agent' => 'PlataformaFiscal-Webhook/1',
                    'X-Webhook-Id' => $entrega->evento_id,
                    'X-Webhook-Event' => $entrega->evento,
                    'X-Signature' => $this->assinador->cabecalho($endpoint->segredo, $t, $corpo),
                ])
                ->timeout(self::TIMEOUT_EM_SEGUNDOS)
                ->withOptions($this->opcoesDeConexao($url))
                ->post($url->url);
        } catch (WebhookUrlInvalidaException|ConnectionException|RespostaGrandeDemaisException|GuzzleException $e) {
            $erro = mb_substr(mb_scrub($e->getMessage()), 0, self::ERRO_MAXIMO);
        }

        $duracao = (int) round((microtime(true) - $inicio) * 1000);
        $this->aplicar($entrega, $endpoint, $resposta, $duracao, $erro);
    }

    private function aplicar(WebhookEntrega $entrega, WebhookEndpoint $endpoint, ?Response $resposta, int $duracao, ?string $erro): void
    {
        DB::transaction(function () use ($entrega, $endpoint, $resposta, $duracao, $erro): void {
            $this->registrar($entrega, $resposta, $duracao, $erro);

            // A reserva já contou esta tentativa.
            $tentativas = $entrega->tentativas;
            $naRodada = $entrega->tentativas_na_rodada;

            if ($resposta?->successful() === true) {
                $entrega->forceFill([
                    'estado' => EstadoDaEntrega::Entregue, 'entregue_em' => now(), 'proxima_tentativa_em' => null,
                    'tentativas' => $tentativas, 'tentativas_na_rodada' => $naRodada,
                ])->save();
                if ($entrega->evento !== EventoDeWebhook::Teste->value) {
                    WebhookEndpoint::query()->whereKey($endpoint->id)->update(['falhas_seguidas' => 0]);
                }

                return;
            }

            // O teste responde na hora e não é retentado nem conta para desativar o endpoint.
            $teste = $entrega->evento === EventoDeWebhook::Teste->value;
            if ($teste || $naRodada >= self::TENTATIVAS_POR_RODADA) {
                $entrega->forceFill([
                    'estado' => EstadoDaEntrega::Falhou, 'proxima_tentativa_em' => null,
                    'tentativas' => $tentativas, 'tentativas_na_rodada' => $naRodada,
                ])->save();
                if (! $teste) {
                    $this->contarFalhaDoEndpoint($endpoint->id);
                }

                return;
            }

            $entrega->forceFill([
                'proxima_tentativa_em' => now()->addSeconds(self::INTERVALOS[$naRodada - 1]),
                'tentativas' => $tentativas, 'tentativas_na_rodada' => $naRodada,
            ])->save();
        });
    }

    private function contarFalhaDoEndpoint(string $endpointId): void
    {
        $endpoint = WebhookEndpoint::query()->lockForUpdate()->find($endpointId);
        if ($endpoint === null) {
            return;
        }
        $falhas = $endpoint->falhas_seguidas + 1;
        $mudancas = ['falhas_seguidas' => $falhas];
        $desativar = $endpoint->ativo && $falhas >= self::FALHAS_PARA_DESATIVAR;
        if ($desativar) {
            $mudancas += ['ativo' => false, 'desativado_em' => now(), 'motivo_desativacao' => self::FALHAS_PARA_DESATIVAR.' entregas seguidas sem sucesso'];
        }
        $endpoint->forceFill($mudancas)->save();

        if ($desativar) {
            activity('webhooks')->performedOn($endpoint)->event('webhook_desativado')
                ->withProperties(['url' => $endpoint->url, 'falhas_seguidas' => $falhas])
                ->log('Webhook desativado por falhas seguidas');
        }
    }

    private function registrar(WebhookEntrega $entrega, ?Response $resposta, int $duracao, ?string $erro): void
    {
        $corpo = $resposta === null ? null : substr($resposta->body(), 0, 4 * self::RESPOSTA_MAXIMA_EM_BYTES);
        $tentativa = new WebhookTentativa;
        $tentativa->forceFill([
            'webhook_entrega_id' => $entrega->id,
            'numero' => $entrega->tentativas,
            'status_http' => $resposta?->status(),
            'duracao_ms' => $duracao,
            'erro' => $erro,
            'resposta' => $corpo === null || $corpo === '' ? null : mb_strcut(mb_scrub($corpo), 0, self::RESPOSTA_MAXIMA_EM_BYTES),
            'created_at' => now(),
        ])->save();
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Billing\Application\Actions;

use App\Modules\Billing\Application\PoliticaDeCobranca;
use App\Modules\Billing\Domain\Contracts\GatewayDeCobranca;
use App\Modules\Billing\Domain\Enums\MetodoDePagamento;
use App\Modules\Billing\Domain\Enums\StatusDaCobranca;
use App\Modules\Billing\Domain\Enums\TipoDeCobranca;
use App\Modules\Billing\Domain\Exceptions\BillingException;
use App\Modules\Billing\Domain\Gateway\DadosDoPagamentoDeCartao;
use App\Modules\Billing\Domain\Gateway\DadosDoPix;
use App\Modules\Billing\Domain\Gateway\ResultadoDePagamento;
use App\Modules\Billing\Domain\Gateway\StatusDoPagamento;
use App\Modules\Billing\Domain\Models\Cobranca;
use App\Modules\Identity\Domain\Models\Usuario;
use App\Modules\Platform\Domain\Models\Plano;
use App\Modules\Tenancy\Domain\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Paga uma cobrança avulsa (hoje, o excedente) por PIX ou cartão. O valor é sempre o da cobrança, nunca do cliente.
 * PIX: devolve o QR code (o mesmo, enquanto valer); o pagamento chega pelo webhook. Cartão: cobra 1x e, se aprovado,
 * concilia na hora (o webhook confirma de novo sem efeito).
 */
final class PagarCobranca
{
    public function __construct(private readonly PoliticaDeCobranca $politica, private readonly GatewayDeCobranca $gateway, private readonly ConciliarPagamento $conciliar) {}

    public function executar(Usuario $autor, string $cobrancaId, MetodoDePagamento $metodo, ?string $tokenDoCartao): Cobranca
    {
        $this->politica->garantirPodeGerenciar($autor);
        if ($metodo === MetodoDePagamento::Boleto) {
            throw new BillingException('BILLING_METODO_INVALIDO', 'Esta cobrança só pode ser paga por PIX ou cartão.');
        }
        if ($metodo === MetodoDePagamento::Cartao && ($tokenDoCartao === null || trim($tokenDoCartao) === '')) {
            throw new BillingException('BILLING_CARTAO_SEM_TOKEN', 'Informe os dados do cartão para continuar.');
        }

        $aprovado = null;
        $cobranca = DB::transaction(function () use ($autor, $cobrancaId, $metodo, $tokenDoCartao, &$aprovado): Cobranca {
            $cobranca = Cobranca::query()->whereKey($cobrancaId)->lockForUpdate()->firstOrFail();
            if (! in_array($cobranca->tipo, [TipoDeCobranca::Excedente, TipoDeCobranca::Upgrade], true) || ! in_array($cobranca->status, [StatusDaCobranca::Pendente, StatusDaCobranca::Vencida], true)) {
                throw new BillingException('BILLING_COBRANCA_NAO_PAGAVEL', 'Esta cobrança não está disponível para pagamento.');
            }
            $tenant = Tenant::query()->findOrFail($autor->tenant_id);

            try {
                if ($metodo === MetodoDePagamento::Pix) {
                    $this->pix($cobranca, $autor, $tenant);
                } else {
                    $aprovado = $this->cartao($cobranca, $autor, $tenant, (string) $tokenDoCartao);
                }
            } catch (BillingException $e) {
                throw $e;
            } catch (Throwable $e) {
                report($e);
                throw new BillingException('BILLING_GATEWAY_FALHOU', 'Não foi possível falar com o Mercado Pago. Tente novamente.', 502);
            }

            return $cobranca;
        });

        if ($aprovado !== null) {
            try {
                $this->conciliar->executar($aprovado);
            } catch (Throwable $e) {
                report($e);   // o webhook do pagamento refaz a conciliação
            }
        }

        return $cobranca->refresh();
    }

    private function pix(Cobranca $cobranca, Usuario $autor, Tenant $tenant): void
    {
        if ($cobranca->metodo === MetodoDePagamento::Pix && $cobranca->gateway_pagamento_id !== null && $cobranca->pix_expira_em?->isFuture() === true) {
            return;   // o PIX gerado ainda vale: devolve o mesmo
        }

        if ($this->cartaoEmAnalise($cobranca)) {
            throw $this->emAndamento('Há um pagamento por cartão em análise para esta cobrança. Aguarde a confirmação antes de gerar um PIX.');
        }

        $expiraEm = CarbonImmutable::now()->addMinutes((int) config('billing.pix_expira_minutos'));
        // Primeira tentativa usa uma chave derivada do id da cobrança; um PIX novo (o anterior expirou) precisa de chave nova.
        $idempotencia = $cobranca->gateway_pagamento_id === null ? 'pix:'.$cobranca->id : (string) Str::uuid();
        $resultado = $this->gateway->criarPix(new DadosDoPix(
            $cobranca->id, $cobranca->valor_centavos, $this->descricao($cobranca), $autor->email, $tenant->nomeDeExibicao(), $tenant->cnpj, $expiraEm, $idempotencia,
        ));

        $cobranca->forceFill([
            'metodo' => MetodoDePagamento::Pix, 'gateway_pagamento_id' => $resultado->id, 'pix_qr_code' => $resultado->pixCopiaECola,
            'pix_qr_code_base64' => $resultado->pixQrCodeBase64, 'pix_expira_em' => ($resultado->pixExpiraEm ?? $expiraEm)->toDateTimeString(),
        ])->save();
    }

    /** @return ?string o id do pagamento aprovado, para conciliar depois do commit */
    private function cartao(Cobranca $cobranca, Usuario $autor, Tenant $tenant, string $token): ?string
    {
        if ($cobranca->metodo === MetodoDePagamento::Pix && $cobranca->pix_expira_em?->isFuture() === true) {
            throw $this->emAndamento('H� um PIX v�lido para esta cobran�a. Pague por ele ou aguarde a expira��o para pagar por cart�o.');
        }

        // A chave vem do token (uso �nico): repetir o pedido por timeout � seguro e uma nova tentativa com outro cart�o n�o
        // recebe de volta a recusa guardada da anterior.
        $resultado = $this->gateway->criarPagamentoDeCartao(new DadosDoPagamentoDeCartao(
            $cobranca->id, $cobranca->valor_centavos, $this->descricao($cobranca), $autor->email, $tenant->nomeDeExibicao(), $tenant->cnpj, $token,
            hash('sha256', 'cartao:'.$cobranca->id.':'.$token),
        ));
        if (! in_array($resultado->status, [StatusDoPagamento::Aprovado, StatusDoPagamento::Pendente], true)) {
            throw new BillingException('BILLING_CARTAO_RECUSADO', 'O cartão não foi aprovado. Confira os dados ou use outro cartão.');
        }

        $cobranca->forceFill(['metodo' => MetodoDePagamento::Cartao, 'gateway_pagamento_id' => $resultado->id])->save();

        return $this->aprovado($resultado) ? $resultado->id : null;
    }

    /** Cart�o pendente: o pagamento ainda pode ser aprovado, ent�o um PIX em paralelo geraria cobran�a em dobro. */
    private function cartaoEmAnalise(Cobranca $cobranca): bool
    {
        return $cobranca->metodo === MetodoDePagamento::Cartao && $cobranca->gateway_pagamento_id !== null
            && $cobranca->updated_at?->gt(now()->subMinutes((int) config('billing.pix_expira_minutos'))) === true;
    }

    private function emAndamento(string $mensagem): BillingException
    {
        return new BillingException('BILLING_PAGAMENTO_EM_ANDAMENTO', $mensagem, 409);
    }

    private function aprovado(ResultadoDePagamento $resultado): bool
    {
        return $resultado->status === StatusDoPagamento::Aprovado;
    }

    private function descricao(Cobranca $cobranca): string
    {
        if ($cobranca->tipo === TipoDeCobranca::Upgrade) {
            $destino = $cobranca->plano_destino_id === null ? null : Plano::query()->find($cobranca->plano_destino_id);

            return 'Troca para o plano '.($destino->nome ?? 'novo');
        }
        $mes = $cobranca->mes_referencia?->format('m/Y');

        return $mes === null ? 'Documentos acima do limite do plano' : 'Documentos acima do limite do plano em '.$mes;
    }
}

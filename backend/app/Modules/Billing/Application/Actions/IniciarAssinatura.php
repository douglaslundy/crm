<?php

declare(strict_types=1);

namespace App\Modules\Billing\Application\Actions;

use App\Modules\Billing\Application\PoliticaDeCobranca;
use App\Modules\Billing\Domain\Contracts\GatewayDeCobranca;
use App\Modules\Billing\Domain\Enums\CicloDeCobranca;
use App\Modules\Billing\Domain\Enums\MetodoDePagamento;
use App\Modules\Billing\Domain\Enums\StatusDaAssinatura;
use App\Modules\Billing\Domain\Enums\StatusDaCobranca;
use App\Modules\Billing\Domain\Enums\TipoDeCobranca;
use App\Modules\Billing\Domain\Exceptions\BillingException;
use App\Modules\Billing\Domain\Gateway\DadosDaAssinatura;
use App\Modules\Billing\Domain\Gateway\DadosDoBoleto;
use App\Modules\Billing\Domain\Models\Assinatura;
use App\Modules\Billing\Domain\Models\Cobranca;
use App\Modules\Identity\Domain\Models\Usuario;
use App\Modules\Platform\Domain\Models\Plano;
use App\Modules\Tenancy\Domain\Enums\SituacaoAssinatura;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Cria a assinatura e a primeira cobrança e pede ao gateway o cartão recorrente ou o boleto. O valor e a descrição
 * são do servidor. Só o pagamento confirmado (webhook) ativa o tenant. Falha do gateway desfaz tudo.
 */
final class IniciarAssinatura
{
    public function __construct(private readonly PoliticaDeCobranca $politica, private readonly GatewayDeCobranca $gateway) {}

    public function executar(Usuario $autor, string $planoId, CicloDeCobranca $ciclo, MetodoDePagamento $metodo, ?string $tokenDoCartao): ResultadoDoCheckout
    {
        $this->politica->garantirPodeGerenciar($autor);

        if ($autor->tenant?->situacao === SituacaoAssinatura::Cancelada) {
            throw new BillingException('BILLING_EMPRESA_CANCELADA', 'Esta empresa foi cancelada e não pode assinar de novo por aqui. Fale com o suporte.', 403);
        }

        $plano = Plano::query()->where('ativo', true)->where('visivel', true)->find($planoId);
        if ($plano === null) {
            throw new BillingException('BILLING_PLANO_INDISPONIVEL', 'Este plano não está disponível para assinatura.');
        }
        $valor = $ciclo->precoDo($plano);
        if ($metodo === MetodoDePagamento::Cartao && ($tokenDoCartao === null || trim($tokenDoCartao) === '')) {
            throw new BillingException('BILLING_CARTAO_SEM_TOKEN', 'Informe os dados do cartão para continuar.');
        }

        return DB::transaction(function () use ($autor, $plano, $ciclo, $metodo, $tokenDoCartao, $valor): ResultadoDoCheckout {
            $this->liberarPendentesAntigas();

            $hoje = CarbonImmutable::today((string) config('app.fuso_negocio'));
            $assinatura = new Assinatura;
            $assinatura->forceFill([
                'plano_id' => $plano->id, 'ciclo' => $ciclo, 'metodo' => $metodo, 'situacao' => StatusDaAssinatura::Pendente,
            ])->save();
            $vencimento = $metodo === MetodoDePagamento::Boleto ? $hoje->addDays((int) config('billing.dias_primeiro_boleto')) : $hoje;
            $cobranca = new Cobranca;
            $cobranca->forceFill([
                'assinatura_id' => $assinatura->id, 'tipo' => TipoDeCobranca::Assinatura, 'valor_centavos' => $valor,
                'vence_em' => $vencimento->toDateString(), 'status' => StatusDaCobranca::Pendente, 'metodo' => $metodo,
            ])->save();

            $descricao = 'Assinatura '.$plano->nome.' ('.($ciclo === CicloDeCobranca::Anual ? 'anual' : 'mensal').')';
            try {
                if ($metodo === MetodoDePagamento::Cartao) {
                    $resultado = $this->gateway->criarAssinaturaDeCartao(new DadosDaAssinatura(
                        $cobranca->id, $valor, $descricao, $ciclo->meses(), $autor->email, (string) $tokenDoCartao, $cobranca->id,
                    ));
                    if (! $resultado->autorizada) {
                        throw new BillingException('BILLING_CARTAO_RECUSADO', 'O cartão não foi autorizado. Confira os dados ou use outro cartão.');
                    }
                    $assinatura->forceFill(['gateway_assinatura_id' => $resultado->id])->save();
                } else {
                    $tenant = $autor->tenant;
                    $resultado = $this->gateway->criarBoleto(new DadosDoBoleto(
                        $cobranca->id, $valor, $descricao, $autor->email, $tenant?->nomeDeExibicao() ?? $autor->nome,
                        (string) $tenant?->cnpj, $vencimento, $cobranca->id,
                    ));
                    $cobranca->forceFill([
                        'gateway_pagamento_id' => $resultado->id, 'boleto_url' => $resultado->boletoUrl, 'boleto_linha_digitavel' => $resultado->linhaDigitavel,
                    ])->save();
                }
            } catch (BillingException $e) {
                throw $e;
            } catch (Throwable $e) {
                report($e);
                throw new BillingException('BILLING_GATEWAY_FALHOU', 'Não foi possível falar com o Mercado Pago. Tente novamente.', 502);
            }

            return new ResultadoDoCheckout($assinatura->refresh(), $cobranca->refresh());
        });
    }

    /**
     * Uma assinatura não cancelada por tenant. Pendente sem cobrança aberta (boleto vencido ou cancelado) não
     * segura o cliente: é cancelada e refeita. Ativa, ou pendente com cobrança em aberto, recusa.
     */
    private function liberarPendentesAntigas(): void
    {
        $hoje = CarbonImmutable::today((string) config('app.fuso_negocio'))->toDateString();
        $existentes = Assinatura::query()->whereIn('situacao', [StatusDaAssinatura::Pendente->value, StatusDaAssinatura::Ativa->value])->lockForUpdate()->get();
        foreach ($existentes as $existente) {
            $aberta = $existente->cobrancas()->where('status', StatusDaCobranca::Pendente->value)->whereDate('vence_em', '>=', $hoje)->exists();
            if ($existente->situacao === StatusDaAssinatura::Ativa || $aberta) {
                throw new BillingException('BILLING_ASSINATURA_ATIVA', 'Esta empresa já tem uma assinatura. Cancele a atual antes de assinar de novo.', 409);
            }
            $existente->cobrancas()->where('status', StatusDaCobranca::Pendente->value)->update(['status' => StatusDaCobranca::Cancelada->value]);
            $existente->forceFill(['situacao' => StatusDaAssinatura::Cancelada, 'cancelada_em' => now()])->save();
        }
    }
}

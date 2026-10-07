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
use App\Modules\Tenancy\Domain\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Cria a assinatura e a primeira cobrança e pede ao gateway o cartão recorrente ou o boleto. O valor e a descrição
 * são do servidor. Só o pagamento confirmado (webhook) ativa o tenant. Falha do gateway desfaz tudo, inclusive a
 * assinatura recorrente que o gateway já tenha criado. Quem tem assinatura ativa mas está atrasado (inadimplente ou
 * suspenso) regulariza com um novo boleto na mesma assinatura, em vez de abrir outra.
 */
final class IniciarAssinatura
{
    public function __construct(private readonly PoliticaDeCobranca $politica, private readonly GatewayDeCobranca $gateway) {}

    public function executar(Usuario $autor, string $planoId, CicloDeCobranca $ciclo, MetodoDePagamento $metodo, ?string $tokenDoCartao): ResultadoDoCheckout
    {
        $this->politica->garantirPodeGerenciar($autor);
        if ($metodo === MetodoDePagamento::Cartao && ($tokenDoCartao === null || trim($tokenDoCartao) === '')) {
            throw new BillingException('BILLING_CARTAO_SEM_TOKEN', 'Informe os dados do cartão para continuar.');
        }

        $preapprovalCriado = null;
        try {
            return DB::transaction(function () use ($autor, $planoId, $ciclo, $metodo, $tokenDoCartao, &$preapprovalCriado): ResultadoDoCheckout {
                // Uma venda de cada vez por empresa: serializa checkouts simultâneos (o índice único do banco é a rede de segurança).
                $tenant = Tenant::query()->lockForUpdate()->findOrFail($autor->tenant_id);
                if ($tenant->situacao === SituacaoAssinatura::Cancelada) {
                    throw new BillingException('BILLING_EMPRESA_CANCELADA', 'Esta empresa foi cancelada e não pode assinar de novo por aqui. Fale com o suporte.', 403);
                }

                $atrasada = $this->assinaturaParaRegularizar($tenant, $metodo);
                if ($atrasada !== null) {
                    return $this->regularizar($atrasada, $autor, $tenant);
                }

                $plano = Plano::query()->where('ativo', true)->where('visivel', true)->find($planoId);
                if ($plano === null) {
                    throw new BillingException('BILLING_PLANO_INDISPONIVEL', 'Este plano não está disponível para assinatura.');
                }
                $valor = $ciclo->precoDo($plano);
                $this->liberarPendentesAntigas();

                $hoje = CarbonImmutable::today((string) config('app.fuso_negocio'));
                $assinatura = new Assinatura;
                $assinatura->forceFill([
                    'plano_id' => $plano->id, 'ciclo' => $ciclo, 'metodo' => $metodo, 'situacao' => StatusDaAssinatura::Pendente,
                ])->save();
                $vencimento = $metodo === MetodoDePagamento::Boleto ? $hoje->addDays((int) config('billing.dias_primeiro_boleto')) : $hoje;
                $cobranca = $this->novaCobranca($assinatura, $valor, $vencimento, $metodo);
                $descricao = $this->descricao($plano, $ciclo);

                try {
                    if ($metodo === MetodoDePagamento::Cartao) {
                        $resultado = $this->gateway->criarAssinaturaDeCartao(new DadosDaAssinatura(
                            $cobranca->id, $valor, $descricao, $ciclo->meses(), $autor->email, (string) $tokenDoCartao, $cobranca->id,
                        ));
                        $preapprovalCriado = $resultado->id;
                        if (! $resultado->autorizada) {
                            throw new BillingException('BILLING_CARTAO_RECUSADO', 'O cartão não foi autorizado. Confira os dados ou use outro cartão.');
                        }
                        $assinatura->forceFill(['gateway_assinatura_id' => $resultado->id])->save();
                    } else {
                        $this->pedirBoleto($cobranca, $descricao, $autor, $tenant, $vencimento);
                    }
                } catch (BillingException $e) {
                    throw $e;
                } catch (Throwable $e) {
                    report($e);
                    throw $this->falhaDoGateway();
                }

                return new ResultadoDoCheckout($assinatura->refresh(), $cobranca->refresh());
            });
        } catch (Throwable $e) {
            // O gateway pode ter criado a assinatura recorrente antes de a transação falhar: sem registro nosso ela
            // cobraria para sempre. Cancela por melhor esforço.
            if ($preapprovalCriado !== null) {
                try {
                    $this->gateway->cancelarAssinatura($preapprovalCriado);
                } catch (Throwable $cancelamento) {
                    report($cancelamento);
                }
            }
            throw $e;
        }
    }

    /**
     * Assinatura ativa de empresa atrasada (inadimplente ou suspensa) que paga por boleto: o novo boleto entra nela.
     * Boleto ainda em aberto recusa: o cliente deve pagar o que já foi emitido.
     */
    private function assinaturaParaRegularizar(Tenant $tenant, MetodoDePagamento $metodo): ?Assinatura
    {
        if ($metodo !== MetodoDePagamento::Boleto || ! in_array($tenant->situacao, [SituacaoAssinatura::Inadimplente, SituacaoAssinatura::Suspensa], true)) {
            return null;
        }
        $ativa = Assinatura::query()->where('situacao', StatusDaAssinatura::Ativa->value)->lockForUpdate()->first();
        if ($ativa === null) {
            return null;
        }
        $hoje = CarbonImmutable::today((string) config('app.fuso_negocio'))->toDateString();
        $emAberto = $ativa->cobrancas()->where('metodo', MetodoDePagamento::Boleto->value)->where('status', StatusDaCobranca::Pendente->value)->whereDate('vence_em', '>=', $hoje)->exists();
        if ($emAberto) {
            throw new BillingException('BILLING_ASSINATURA_ATIVA', 'Já existe um boleto em aberto para esta assinatura. Pague o boleto emitido (veja em Faturas).', 409);
        }

        return $ativa;
    }

    private function regularizar(Assinatura $assinatura, Usuario $autor, Tenant $tenant): ResultadoDoCheckout
    {
        $vencimento = CarbonImmutable::today((string) config('app.fuso_negocio'))->addDays((int) config('billing.dias_primeiro_boleto'));
        $anterior = Cobranca::query()->where('assinatura_id', $assinatura->id)->where('status', StatusDaCobranca::Paga->value)->latest('pago_em')->value('valor_centavos');
        $valor = is_int($anterior) ? $anterior : $assinatura->ciclo->precoDo($assinatura->plano);
        $cobranca = $this->novaCobranca($assinatura, $valor, $vencimento, MetodoDePagamento::Boleto);

        try {
            $this->pedirBoleto($cobranca, $this->descricao($assinatura->plano, $assinatura->ciclo), $autor, $tenant, $vencimento);
        } catch (BillingException $e) {
            throw $e;
        } catch (Throwable $e) {
            report($e);
            throw $this->falhaDoGateway();
        }

        return new ResultadoDoCheckout($assinatura->refresh(), $cobranca->refresh());
    }

    private function novaCobranca(Assinatura $assinatura, int $valor, CarbonImmutable $vencimento, MetodoDePagamento $metodo): Cobranca
    {
        $cobranca = new Cobranca;
        $cobranca->forceFill([
            'assinatura_id' => $assinatura->id, 'tipo' => TipoDeCobranca::Assinatura, 'valor_centavos' => $valor,
            'vence_em' => $vencimento->toDateString(), 'status' => StatusDaCobranca::Pendente, 'metodo' => $metodo,
        ])->save();

        return $cobranca;
    }

    private function pedirBoleto(Cobranca $cobranca, string $descricao, Usuario $autor, Tenant $tenant, CarbonImmutable $vencimento): void
    {
        $resultado = $this->gateway->criarBoleto(new DadosDoBoleto(
            $cobranca->id, $cobranca->valor_centavos, $descricao, $autor->email, $tenant->nomeDeExibicao(), $tenant->cnpj, $vencimento, $cobranca->id,
        ));
        $cobranca->forceFill([
            'gateway_pagamento_id' => $resultado->id, 'boleto_url' => $resultado->boletoUrl, 'boleto_linha_digitavel' => $resultado->linhaDigitavel,
        ])->save();
    }

    private function descricao(Plano $plano, CicloDeCobranca $ciclo): string
    {
        return 'Assinatura '.$plano->nome.' ('.($ciclo === CicloDeCobranca::Anual ? 'anual' : 'mensal').')';
    }

    private function falhaDoGateway(): BillingException
    {
        return new BillingException('BILLING_GATEWAY_FALHOU', 'Não foi possível falar com o Mercado Pago. Tente novamente.', 502);
    }

    /**
     * Uma assinatura não cancelada por tenant. A pendente é cancelada (no gateway também) e refeita, exceto enquanto um
     * boleto dela ainda pode ser compensado: vencido há poucos dias, o pagamento pode estar a caminho e cairia numa
     * assinatura cancelada. Ativa recusa.
     */
    private function liberarPendentesAntigas(): void
    {
        $limite = CarbonImmutable::today((string) config('app.fuso_negocio'))->subDays((int) config('billing.tolerancia_compensacao_dias'))->toDateString();
        $existentes = Assinatura::query()->whereIn('situacao', [StatusDaAssinatura::Pendente->value, StatusDaAssinatura::Ativa->value])->lockForUpdate()->get();
        foreach ($existentes as $existente) {
            $boletoPagavel = $existente->cobrancas()->where('metodo', MetodoDePagamento::Boleto->value)
                ->whereIn('status', [StatusDaCobranca::Pendente->value, StatusDaCobranca::Vencida->value])->whereDate('vence_em', '>=', $limite)->exists();
            if ($existente->situacao === StatusDaAssinatura::Ativa || $boletoPagavel) {
                throw new BillingException('BILLING_ASSINATURA_ATIVA', 'Esta empresa já tem uma assinatura ou um boleto em aberto. Pague o boleto emitido ou cancele a assinatura atual antes de assinar de novo.', 409);
            }
            if ($existente->gateway_assinatura_id !== null) {
                try {
                    $this->gateway->cancelarAssinatura($existente->gateway_assinatura_id);
                } catch (Throwable $e) {
                    report($e);
                }
            }
            $existente->cobrancas()->where('status', StatusDaCobranca::Pendente->value)->update(['status' => StatusDaCobranca::Cancelada->value]);
            $existente->forceFill(['situacao' => StatusDaAssinatura::Cancelada, 'cancelada_em' => now()])->save();
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Billing\Console;

use App\Modules\Billing\Domain\Contracts\GatewayDeCobranca;
use App\Modules\Billing\Domain\Enums\CicloDeCobranca;
use App\Modules\Billing\Domain\Enums\MetodoDePagamento;
use App\Modules\Billing\Domain\Enums\StatusDaAssinatura;
use App\Modules\Billing\Domain\Enums\StatusDaCobranca;
use App\Modules\Billing\Domain\Enums\TipoDeCobranca;
use App\Modules\Billing\Domain\Gateway\DadosDoBoleto;
use App\Modules\Billing\Domain\Models\Assinatura;
use App\Modules\Billing\Domain\Models\Cobranca;
use App\Modules\Identity\Domain\Enums\Papel;
use App\Modules\Identity\Domain\Models\Usuario;
use App\Modules\Platform\Domain\Models\Plano;
use App\Modules\Tenancy\Domain\Models\Tenant;
use App\Modules\Tenancy\Domain\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

/**
 * Gera o boleto do próximo ciclo das assinaturas de boleto ativas, `antecedencia_boleto_dias` antes do fim do período
 * pago. O Mercado Pago não renova boleto sozinho.
 *
 * - Uma cobrança pendente por assinatura: com boleto já emitido, não duplica.
 * - Se o gateway falhar a cobrança fica sem boleto (ele pode ter sido criado do lado de lá): o dia seguinte refaz
 *   com o mesmo id como chave de idempotência, em vez de abrir outra cobrança e outro boleto.
 * - Assinatura com cobrança vencida não ganha boleto novo todo dia: quem regulariza é o cliente, pelo checkout.
 * - Boleto vencido não se paga: sem cobrança em aberto e com o período já vencido, o novo boleto vence no futuro.
 * - Uma falha numa assinatura não impede as outras.
 */
final class GerarBoletosDoCicloCommand extends Command
{
    protected $signature = 'billing:gerar-boletos';

    protected $description = 'Gera o boleto do próximo ciclo das assinaturas de boleto que estão para vencer.';

    public function handle(GatewayDeCobranca $gateway, TenantContext $contexto): int
    {
        $hoje = CarbonImmutable::today((string) config('app.fuso_negocio'));
        $limite = $hoje->addDays((int) config('billing.antecedencia_boleto_dias'))->toDateString();
        $gerados = 0;

        $anterior = $contexto->id();
        try {
            Assinatura::withoutTenantScope()
                ->where('situacao', StatusDaAssinatura::Ativa->value)
                ->where('metodo', MetodoDePagamento::Boleto->value)
                ->whereNotNull('paga_ate')
                ->whereDate('paga_ate', '<=', $limite)
                ->lazyById(100)
                ->each(function (Assinatura $assinatura) use ($gateway, $contexto, $hoje, &$gerados): void {
                    $contexto->set($assinatura->tenant_id);
                    if ($this->gerar($assinatura, $gateway, $hoje)) {
                        $gerados++;
                    }
                });
        } finally {
            $contexto->set($anterior);
        }

        $this->info("{$gerados} boleto(s) gerado(s).");

        return self::SUCCESS;
    }

    private function gerar(Assinatura $assinatura, GatewayDeCobranca $gateway, CarbonImmutable $hoje): bool
    {
        $tenant = Tenant::query()->find($assinatura->tenant_id);
        $pagador = Usuario::query()->where('tenant_id', $assinatura->tenant_id)
            ->whereIn('papel', [Papel::Proprietario->value, Papel::Admin->value])->oldest()->first();
        if ($tenant === null || $pagador === null || $assinatura->paga_ate === null) {
            return false;
        }

        $daAssinatura = Cobranca::query()->where('assinatura_id', $assinatura->id)->where('tipo', TipoDeCobranca::Assinatura->value);
        if ((clone $daAssinatura)->where('status', StatusDaCobranca::Vencida->value)->exists()) {
            return false;
        }
        $pendente = (clone $daAssinatura)->where('status', StatusDaCobranca::Pendente->value)->latest()->first();
        if ($pendente !== null && $pendente->gateway_pagamento_id !== null) {
            return false;   // boleto já emitido
        }

        // Downgrade agendado: o próximo período já é cobrado no preço do plano novo.
        $plano = ($assinatura->plano_proximo_id === null ? null : Plano::query()->find($assinatura->plano_proximo_id)) ?? $assinatura->plano;

        try {
            $cobranca = $pendente ?? new Cobranca;
            $vencePadrao = $this->vencimento($assinatura, $hoje);
            if ($pendente === null) {
                $cobranca->forceFill([
                    'assinatura_id' => $assinatura->id, 'tipo' => TipoDeCobranca::Assinatura,
                    'valor_centavos' => $assinatura->ciclo->precoDo($plano),
                    'vence_em' => $vencePadrao->toDateString(), 'status' => StatusDaCobranca::Pendente, 'metodo' => MetodoDePagamento::Boleto,
                ])->save();
            } elseif ($pendente->vence_em->lessThan($hoje)) {
                $pendente->forceFill(['vence_em' => $vencePadrao->toDateString()])->save();   // retentativa de um boleto que não saiu a tempo
            }

            $descricao = 'Assinatura '.$plano->nome.' ('.($assinatura->ciclo === CicloDeCobranca::Anual ? 'anual' : 'mensal').')';
            $resultado = $gateway->criarBoleto(new DadosDoBoleto(
                $cobranca->id, $cobranca->valor_centavos, $descricao, $pagador->email, $tenant->nomeDeExibicao(), $tenant->cnpj,
                CarbonImmutable::parse($cobranca->vence_em->toDateString()), $cobranca->id,
            ));
            $cobranca->forceFill([
                'gateway_pagamento_id' => $resultado->id, 'boleto_url' => $resultado->boletoUrl, 'boleto_linha_digitavel' => $resultado->linhaDigitavel,
            ])->save();

            return true;
        } catch (Throwable $e) {
            // A cobrança fica (sem boleto) para o próximo dia refazer com a mesma chave de idempotência.
            report($e);

            return false;
        }
    }

    /** O boleto vence no fim do período pago; com o período já vencido, daqui a `dias_primeiro_boleto` dias. */
    private function vencimento(Assinatura $assinatura, CarbonImmutable $hoje): CarbonImmutable
    {
        $fimDoPeriodo = CarbonImmutable::parse((string) $assinatura->paga_ate?->toDateString());

        return $fimDoPeriodo->lessThan($hoje) ? $hoje->addDays((int) config('billing.dias_primeiro_boleto')) : $fimDoPeriodo;
    }
}

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
use App\Modules\Tenancy\Domain\Models\Tenant;
use App\Modules\Tenancy\Domain\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

/**
 * Gera o boleto do próximo ciclo das assinaturas de boleto ativas, `antecedencia_boleto_dias` antes do fim do período
 * pago. O Mercado Pago não renova boleto sozinho. Não duplica (uma cobrança pendente por assinatura) e uma falha do
 * gateway numa assinatura não impede as outras.
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
        Assinatura::withoutTenantScope()
            ->where('situacao', StatusDaAssinatura::Ativa->value)
            ->where('metodo', MetodoDePagamento::Boleto->value)
            ->whereNotNull('paga_ate')
            ->whereDate('paga_ate', '<=', $limite)
            ->lazyById(100)
            ->each(function (Assinatura $assinatura) use ($gateway, $contexto, &$gerados): void {
                $contexto->set($assinatura->tenant_id);
                if ($this->gerar($assinatura, $gateway)) {
                    $gerados++;
                }
            });
        $contexto->set($anterior);

        $this->info("{$gerados} boleto(s) gerado(s).");

        return self::SUCCESS;
    }

    private function gerar(Assinatura $assinatura, GatewayDeCobranca $gateway): bool
    {
        $jaTem = Cobranca::query()->where('assinatura_id', $assinatura->id)
            ->where('tipo', TipoDeCobranca::Assinatura->value)->where('status', StatusDaCobranca::Pendente->value)->exists();
        $pagador = Usuario::query()->where('tenant_id', $assinatura->tenant_id)
            ->whereIn('papel', [Papel::Proprietario->value, Papel::Admin->value])->oldest()->first();
        $tenant = Tenant::query()->find($assinatura->tenant_id);
        if ($jaTem || $pagador === null || $tenant === null || $assinatura->paga_ate === null) {
            return false;
        }

        $vencimento = CarbonImmutable::parse($assinatura->paga_ate->toDateString());
        $cobranca = new Cobranca;
        try {
            $valor = $assinatura->ciclo->precoDo($assinatura->plano);
            $cobranca->forceFill([
                'assinatura_id' => $assinatura->id, 'tipo' => TipoDeCobranca::Assinatura, 'valor_centavos' => $valor,
                'vence_em' => $vencimento->toDateString(), 'status' => StatusDaCobranca::Pendente, 'metodo' => MetodoDePagamento::Boleto,
            ])->save();
            $descricao = 'Assinatura '.$assinatura->plano->nome.' ('.($assinatura->ciclo === CicloDeCobranca::Anual ? 'anual' : 'mensal').')';
            $resultado = $gateway->criarBoleto(new DadosDoBoleto(
                $cobranca->id, $valor, $descricao, $pagador->email, $tenant->nomeDeExibicao(), $tenant->cnpj, $vencimento, $cobranca->id,
            ));
            $cobranca->forceFill([
                'gateway_pagamento_id' => $resultado->id, 'boleto_url' => $resultado->boletoUrl, 'boleto_linha_digitavel' => $resultado->linhaDigitavel,
            ])->save();

            return true;
        } catch (Throwable $e) {
            report($e);
            if ($cobranca->exists) {
                $cobranca->delete();   // sem boleto no gateway não há cobrança para guardar; o próximo dia tenta de novo
            }

            return false;
        }
    }
}

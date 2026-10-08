<?php

declare(strict_types=1);

namespace App\Modules\Billing\Application\Actions;

use App\Modules\Billing\Domain\Contracts\GatewayDeCobranca;
use App\Modules\Billing\Domain\Enums\MetodoDePagamento;
use App\Modules\Billing\Domain\Enums\StatusDaAssinatura;
use App\Modules\Billing\Domain\Exceptions\BillingException;
use App\Modules\Billing\Domain\Models\Assinatura;
use App\Modules\Platform\Application\EntitlementService;
use App\Modules\Platform\Domain\Models\Plano;
use App\Modules\Tenancy\Domain\Enums\SituacaoAssinatura;
use App\Modules\Tenancy\Domain\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Chegou o fim do período pago: troca o plano de quem agendou um downgrade. Confere o uso de novo; se agora passa dos
 * limites do plano novo, o agendamento é cancelado (e o valor do cartão devolvido ao do plano atual) em vez de a empresa
 * ficar acima do limite. Roda sem usuário, com o contexto do tenant já definido. Idempotente.
 */
final class AplicarDowngrade
{
    public function __construct(private readonly EntitlementService $entitlements, private readonly GatewayDeCobranca $gateway) {}

    /** @return bool true quando o plano foi trocado */
    public function executar(string $assinaturaId): bool
    {
        $hoje = CarbonImmutable::today((string) config('app.fuso_negocio'));
        $reverter = null;

        $trocou = DB::transaction(function () use ($assinaturaId, $hoje, &$reverter): bool {
            $assinatura = Assinatura::query()->whereKey($assinaturaId)->lockForUpdate()->first();
            $tenant = $assinatura === null ? null : Tenant::query()->lockForUpdate()->find($assinatura->tenant_id);
            if (
                $assinatura === null || $tenant === null || $assinatura->plano_proximo_id === null || $assinatura->plano_proximo_em === null
                || $assinatura->situacao !== StatusDaAssinatura::Ativa || $tenant->situacao !== SituacaoAssinatura::Ativa
                || $assinatura->plano_proximo_em->toDateString() > $hoje->toDateString()
            ) {
                return false;
            }

            $novo = Plano::query()->find($assinatura->plano_proximo_id);
            $preco = null;
            if ($novo !== null) {
                try {
                    $preco = $assinatura->ciclo->precoDo($novo);
                } catch (BillingException) {
                    $preco = null;
                }
            }
            $excessos = $novo === null ? [] : $this->entitlements->excessos($tenant, $novo);
            if ($novo === null || $preco === null || $excessos !== []) {
                Log::warning('Downgrade agendado cancelado: o plano novo não serve mais para esta empresa.', [
                    'tenant_id' => $tenant->id, 'assinatura_id' => $assinatura->id, 'plano_proximo_id' => $assinatura->plano_proximo_id, 'excessos' => $excessos,
                ]);
                $assinatura->forceFill(['plano_proximo_id' => null, 'plano_proximo_em' => null])->save();
                if ($assinatura->metodo === MetodoDePagamento::Cartao && $assinatura->gateway_assinatura_id !== null) {
                    $reverter = ['assinatura' => $assinatura->gateway_assinatura_id, 'valor' => $assinatura->valorDoCiclo()];
                }

                return false;
            }

            $anterior = $tenant->plano_id;
            $tenant->forceFill(['plano_id' => $novo->id])->save();
            $assinatura->forceFill([
                'plano_id' => $novo->id, 'valor_ciclo_centavos' => $preco, 'plano_proximo_id' => null, 'plano_proximo_em' => null,
            ])->save();
            activity('assinatura')->performedOn($tenant)->event('plano_trocado')
                ->withProperties(['de' => $anterior, 'para' => $novo->id])->log('Plano da empresa trocado (downgrade agendado)');

            return true;
        });

        if ($reverter !== null) {
            try {
                $this->gateway->atualizarAssinatura($reverter['assinatura'], $reverter['valor']);
            } catch (Throwable $e) {
                report($e);
            }
        }

        return $trocou;
    }
}

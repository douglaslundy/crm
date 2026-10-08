<?php

declare(strict_types=1);

namespace App\Modules\Billing\Application\Actions;

use App\Modules\Billing\Application\PoliticaDeCobranca;
use App\Modules\Billing\Domain\Contracts\GatewayDeCobranca;
use App\Modules\Billing\Domain\Enums\MetodoDePagamento;
use App\Modules\Billing\Domain\Enums\StatusDaAssinatura;
use App\Modules\Billing\Domain\Exceptions\BillingException;
use App\Modules\Billing\Domain\Models\Assinatura;
use App\Modules\Identity\Domain\Models\Usuario;
use Illuminate\Support\Facades\DB;
use Throwable;

/** Desfaz o downgrade agendado e, no cartão, devolve o valor do plano atual à recorrência. */
final class CancelarDowngrade
{
    public function __construct(private readonly PoliticaDeCobranca $politica, private readonly GatewayDeCobranca $gateway) {}

    public function executar(Usuario $autor): Assinatura
    {
        $this->politica->garantirPodeGerenciar($autor);

        return DB::transaction(function () use ($autor): Assinatura {
            $assinatura = Assinatura::query()->where('situacao', StatusDaAssinatura::Ativa->value)->lockForUpdate()->first();
            if ($assinatura === null || $assinatura->plano_proximo_id === null) {
                throw new BillingException('BILLING_SEM_DOWNGRADE', 'Não há troca de plano agendada.', 404);
            }

            if ($assinatura->temBoletoEmitidoEmAberto()) {
                throw new BillingException('BILLING_TROCA_BOLETO_EMITIDO', 'O boleto da próxima renovação já foi emitido. Pague-o (ou espere o vencimento) antes de trocar de plano.', 409);
            }

            if ($assinatura->metodo === MetodoDePagamento::Cartao && $assinatura->gateway_assinatura_id !== null) {
                try {
                    $this->gateway->atualizarAssinatura($assinatura->gateway_assinatura_id, $assinatura->valorDoCiclo());
                } catch (BillingException $e) {
                    throw $e;
                } catch (Throwable $e) {
                    report($e);
                    throw new BillingException('BILLING_GATEWAY_FALHOU', 'Não foi possível falar com o Mercado Pago. Tente novamente.', 502);
                }
            }

            $cancelado = $assinatura->plano_proximo_id;
            $assinatura->forceFill(['plano_proximo_id' => null, 'plano_proximo_em' => null])->save();

            activity('assinatura')->performedOn($assinatura)->causedBy($autor)->event('downgrade_cancelado')
                ->withProperties(['plano_cancelado' => $cancelado])->log('Troca de plano agendada cancelada');

            return $assinatura;
        });
    }
}

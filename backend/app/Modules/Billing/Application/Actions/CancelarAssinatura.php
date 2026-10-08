<?php

declare(strict_types=1);

namespace App\Modules\Billing\Application\Actions;

use App\Modules\Billing\Application\PoliticaDeCobranca;
use App\Modules\Billing\Domain\Contracts\GatewayDeCobranca;
use App\Modules\Billing\Domain\Enums\StatusDaAssinatura;
use App\Modules\Billing\Domain\Enums\StatusDaCobranca;
use App\Modules\Billing\Domain\Exceptions\BillingException;
use App\Modules\Billing\Domain\Models\Assinatura;
use App\Modules\Identity\Domain\Models\Usuario;
use Illuminate\Support\Facades\DB;

/** Cancela a renovação. O acesso continua até o fim do período pago (`paga_ate`). */
final class CancelarAssinatura
{
    public function __construct(private readonly PoliticaDeCobranca $politica, private readonly GatewayDeCobranca $gateway) {}

    public function executar(Usuario $autor): Assinatura
    {
        $this->politica->garantirPodeGerenciar($autor);

        return DB::transaction(function (): Assinatura {
            $assinatura = Assinatura::query()->where('situacao', '!=', StatusDaAssinatura::Cancelada->value)->latest()->lockForUpdate()->first();
            if ($assinatura === null) {
                throw new BillingException('BILLING_SEM_ASSINATURA', 'Esta empresa não tem assinatura para cancelar.', 404);
            }
            if ($assinatura->gateway_assinatura_id !== null) {
                $this->gateway->cancelarAssinatura($assinatura->gateway_assinatura_id);
            }
            $assinatura->cobrancas()->where('status', StatusDaCobranca::Pendente->value)->update(['status' => StatusDaCobranca::Cancelada->value]);
            $assinatura->forceFill(['situacao' => StatusDaAssinatura::Cancelada, 'cancelada_em' => now(), 'plano_proximo_id' => null, 'plano_proximo_em' => null])->save();

            return $assinatura;
        });
    }
}

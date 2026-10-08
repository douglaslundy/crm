<?php

declare(strict_types=1);

namespace App\Modules\Billing\Application\Actions;

use App\Modules\Billing\Application\PoliticaDeCobranca;
use App\Modules\Billing\Domain\Contracts\GatewayDeCobranca;
use App\Modules\Billing\Domain\Enums\MetodoDePagamento;
use App\Modules\Billing\Domain\Enums\StatusDaAssinatura;
use App\Modules\Billing\Domain\Enums\StatusDaCobranca;
use App\Modules\Billing\Domain\Enums\TipoDeCobranca;
use App\Modules\Billing\Domain\Exceptions\BillingException;
use App\Modules\Billing\Domain\Models\Assinatura;
use App\Modules\Billing\Domain\Models\Cobranca;
use App\Modules\Billing\Domain\ResultadoDaTroca;
use App\Modules\Billing\Domain\TipoDeTroca;
use App\Modules\Billing\Domain\ValorDoUpgrade;
use App\Modules\Identity\Domain\Models\Usuario;
use App\Modules\Platform\Application\EntitlementService;
use App\Modules\Platform\Domain\Exceptions\PlanoInsuficienteException;
use App\Modules\Platform\Domain\Models\Plano;
use App\Modules\Tenancy\Domain\Enums\SituacaoAssinatura;
use App\Modules\Tenancy\Domain\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Troca de plano no mesmo ciclo. Preço maior: cobrança `UPGRADE` com a diferença proporcional, e o plano só muda quando
 * ela é paga (ConciliarPagamento). Preço menor: agendado para o fim do período pago, se o uso atual couber no plano novo.
 */
final class TrocarDePlano
{
    public function __construct(
        private readonly PoliticaDeCobranca $politica,
        private readonly GatewayDeCobranca $gateway,
        private readonly EntitlementService $entitlements,
    ) {}

    public function executar(Usuario $autor, string $planoId): ResultadoDaTroca
    {
        $this->politica->garantirPodeGerenciar($autor);

        return DB::transaction(function () use ($autor, $planoId): ResultadoDaTroca {
            $tenant = Tenant::query()->lockForUpdate()->findOrFail($autor->tenant_id);
            $assinatura = Assinatura::query()->where('situacao', StatusDaAssinatura::Ativa->value)->lockForUpdate()->first();
            $hoje = CarbonImmutable::today((string) config('app.fuso_negocio'));
            if ($assinatura === null || $tenant->situacao !== SituacaoAssinatura::Ativa || $assinatura->paga_ate === null || $assinatura->paga_ate->toDateString() < $hoje->toDateString()) {
                throw new BillingException('BILLING_TROCA_INDISPONIVEL', 'Só quem tem assinatura ativa e em dia troca de plano. Regularize o pagamento primeiro.');
            }

            $novo = Plano::query()->where('ativo', true)->where('visivel', true)->find($planoId);
            if ($novo === null) {
                throw new BillingException('BILLING_PLANO_INDISPONIVEL', 'Este plano não está disponível para assinatura.');
            }
            if ($novo->id === $assinatura->plano_id) {
                throw new BillingException('BILLING_TROCA_MESMO_PLANO', 'Esta empresa já está neste plano.');
            }
            $precoNovo = $assinatura->ciclo->precoDo($novo);
            $precoAtual = $assinatura->valorDoCiclo();
            if ($precoNovo === $precoAtual) {
                throw new BillingException('BILLING_TROCA_MESMO_PRECO', 'Este plano custa o mesmo que o atual neste ciclo. Fale com o suporte para trocar.');
            }

            $excessos = $this->entitlements->excessos($tenant, $novo);
            if ($excessos !== []) {
                throw new PlanoInsuficienteException($excessos);
            }

            return $precoNovo > $precoAtual
                ? $this->upgrade($assinatura, $novo, $precoAtual, $precoNovo, $hoje)
                : $this->downgrade($assinatura, $novo, $precoNovo, $autor);
        });
    }

    private function upgrade(Assinatura $assinatura, Plano $novo, int $precoAtual, int $precoNovo, CarbonImmutable $hoje): ResultadoDaTroca
    {
        $pendentes = Cobranca::query()->where('assinatura_id', $assinatura->id)->where('tipo', TipoDeCobranca::Upgrade->value)
            ->where('status', StatusDaCobranca::Pendente->value)->lockForUpdate()->get();
        $mesmoDestino = $pendentes->first(fn (Cobranca $c): bool => $c->plano_destino_id === $novo->id);
        if ($mesmoDestino !== null) {
            return new ResultadoDaTroca(TipoDeTroca::Upgrade, $assinatura, $mesmoDestino);
        }
        foreach ($pendentes as $outra) {
            $outra->forceFill(['status' => StatusDaCobranca::Cancelada])->save();
        }

        $valor = ValorDoUpgrade::calcular($precoAtual, $precoNovo, $hoje, CarbonImmutable::parse((string) $assinatura->paga_ate?->toDateString()), $assinatura->ciclo);
        $cobranca = new Cobranca;
        $cobranca->forceFill([
            'assinatura_id' => $assinatura->id, 'tipo' => TipoDeCobranca::Upgrade, 'valor_centavos' => $valor,
            'vence_em' => $hoje->addDay()->toDateString(), 'status' => StatusDaCobranca::Pendente, 'metodo' => null,
            'plano_destino_id' => $novo->id,
        ])->save();

        return new ResultadoDaTroca(TipoDeTroca::Upgrade, $assinatura, $cobranca->refresh());
    }

    private function downgrade(Assinatura $assinatura, Plano $novo, int $precoNovo, Usuario $autor): ResultadoDaTroca
    {
        if ($assinatura->metodo === MetodoDePagamento::Cartao && $assinatura->gateway_assinatura_id !== null) {
            try {
                $this->gateway->atualizarAssinatura($assinatura->gateway_assinatura_id, $precoNovo);
            } catch (BillingException $e) {
                throw $e;
            } catch (Throwable $e) {
                report($e);
                throw new BillingException('BILLING_GATEWAY_FALHOU', 'Não foi possível falar com o Mercado Pago. Tente novamente.', 502);
            }
        }

        $assinatura->forceFill(['plano_proximo_id' => $novo->id, 'plano_proximo_em' => $assinatura->paga_ate?->toDateString()])->save();

        activity('assinatura')->performedOn($assinatura)->causedBy($autor)->event('downgrade_agendado')
            ->withProperties(['de' => $assinatura->plano_id, 'para' => $novo->id, 'em' => $assinatura->paga_ate?->toDateString()])
            ->log('Troca de plano agendada para o fim do período');

        return new ResultadoDaTroca(TipoDeTroca::Downgrade, $assinatura->refresh());
    }
}

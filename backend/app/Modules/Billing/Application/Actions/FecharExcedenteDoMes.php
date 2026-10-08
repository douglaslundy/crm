<?php

declare(strict_types=1);

namespace App\Modules\Billing\Application\Actions;

use App\Modules\Billing\Domain\Enums\StatusDaAssinatura;
use App\Modules\Billing\Domain\Enums\StatusDaCobranca;
use App\Modules\Billing\Domain\Enums\TipoDeCobranca;
use App\Modules\Billing\Domain\Models\Assinatura;
use App\Modules\Billing\Domain\Models\Cobranca;
use App\Modules\Platform\Application\RegistroDeContadores;
use App\Modules\Platform\Domain\Contracts\ContadorDeUsoMensal;
use App\Modules\Platform\Domain\Enums\Recurso;
use App\Modules\Platform\Domain\Models\Plano;
use App\Modules\Tenancy\Domain\Models\Tenant;
use App\Modules\Tenancy\Domain\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Fecha o mês: o documento acima do limite do plano vira UMA cobrança `EXCEDENTE` por tenant e mês, sem método de
 * pagamento (é escolhido ao pagar). Só gera para quem tem assinatura ATIVA e plano `COBRAR` com preço. Idempotente:
 * o índice único parcial do banco garante uma cobrança não cancelada por tenant e mês.
 */
final class FecharExcedenteDoMes
{
    public function __construct(private readonly RegistroDeContadores $contadores, private readonly TenantContext $contexto) {}

    /** @return int quantas cobranças criou */
    public function executar(CarbonImmutable $mes): int
    {
        $contador = $this->contadores->para(Recurso::DocumentosMes);
        if (! $contador instanceof ContadorDeUsoMensal) {
            throw new RuntimeException('O contador de documentos do mês não mede por mês.');
        }

        $mes = $mes->startOfMonth();
        $hoje = CarbonImmutable::today((string) config('app.fuso_negocio'));
        $anterior = $this->contexto->id();
        $criadas = 0;

        try {
            $comAssinatura = [];
            foreach (Assinatura::withoutTenantScope()->where('situacao', StatusDaAssinatura::Ativa->value)->lazyById(100) as $assinatura) {
                $comAssinatura[$assinatura->tenant_id] = true;
                $this->contexto->set($assinatura->tenant_id);
                $tenant = Tenant::query()->find($assinatura->tenant_id);
                $excesso = $tenant === null ? 0 : $this->excessoDo($tenant, $contador, $mes);
                if ($tenant?->plano === null || $excesso <= 0) {
                    continue;
                }

                $criadas += $this->criar($assinatura, $tenant->plano, $mes, $excesso, $hoje);
            }

            // Sem assinatura ativa não há a quem cobrar (teste, cancelada, pendente): o excesso fica só no log.
            foreach (Tenant::query()->whereNotNull('plano_id')->lazyById(100) as $tenant) {
                $excesso = isset($comAssinatura[$tenant->id]) ? 0 : $this->excessoDo($tenant, $contador, $mes);
                if ($excesso > 0) {
                    Log::info('Documentos acima do limite de empresa sem assinatura ativa: nada foi cobrado.', [
                        'tenant_id' => $tenant->id, 'mes' => $mes->format('Y-m'), 'documentos_excedentes' => $excesso,
                    ]);
                }
            }
        } finally {
            $this->contexto->set($anterior);
        }

        return $criadas;
    }

    /** Documentos acima do limite no mês; 0 quando o plano não cobra excedente ou não tem limite. */
    private function excessoDo(Tenant $tenant, ContadorDeUsoMensal $contador, CarbonImmutable $mes): int
    {
        $plano = $tenant->plano;
        if ($plano === null || ! $plano->permiteExcederDocumentos()) {
            return 0;
        }
        $limite = $plano->limiteDe(Recurso::DocumentosMes);
        if ($limite === Plano::ILIMITADO) {
            return 0;
        }

        return max(0, $contador->contarNoMes($tenant, $mes) - $limite);
    }

    private function criar(Assinatura $assinatura, Plano $plano, CarbonImmutable $mes, int $excesso, CarbonImmutable $hoje): int
    {
        $jaFechado = Cobranca::query()->where('tipo', TipoDeCobranca::Excedente->value)
            ->whereDate('mes_referencia', $mes->toDateString())
            ->where('status', '!=', StatusDaCobranca::Cancelada->value)->exists();
        if ($jaFechado) {
            return 0;
        }
        $unitario = (int) $plano->preco_documento_excedente_centavos;

        try {
            $cobranca = new Cobranca;
            $cobranca->forceFill([
                'assinatura_id' => $assinatura->id,
                'tipo' => TipoDeCobranca::Excedente,
                'valor_centavos' => $excesso * $unitario,
                'vence_em' => $hoje->addDays((int) config('billing.dias_vencimento_excedente'))->toDateString(),
                'status' => StatusDaCobranca::Pendente,
                'metodo' => null,
                'mes_referencia' => $mes->toDateString(),
                'documentos_excedentes' => $excesso,
                'valor_unitario_centavos' => $unitario,
            ])->save();
        } catch (UniqueConstraintViolationException) {
            // Corrida com outro processo: o índice único do banco decide.
            Log::info('Excedente do mês já fechado.', ['tenant_id' => $assinatura->tenant_id, 'mes' => $mes->format('Y-m')]);

            return 0;
        }

        return 1;
    }
}

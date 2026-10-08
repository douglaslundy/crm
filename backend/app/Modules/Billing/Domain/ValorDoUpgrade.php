<?php

declare(strict_types=1);

namespace App\Modules\Billing\Domain;

use App\Modules\Billing\Domain\Enums\CicloDeCobranca;
use App\Modules\Billing\Domain\Exceptions\BillingException;
use Carbon\CarbonImmutable;

/** Diferença do upgrade: `(novo - atual) x dias restantes / dias do ciclo`, em centavos inteiros (meio centavo sobe). */
final class ValorDoUpgrade
{
    public static function calcular(int $precoAtual, int $precoNovo, CarbonImmutable $hoje, CarbonImmutable $pagaAte, CicloDeCobranca $ciclo): int
    {
        // Só o dia importa: ambos viram meia-noite UTC, senão fusos diferentes dariam fração de dia.
        $hoje = CarbonImmutable::parse($hoje->toDateString(), 'UTC');
        $pagaAte = CarbonImmutable::parse($pagaAte->toDateString(), 'UTC');
        $restantes = (int) $hoje->diffInDays($pagaAte);   // negativo quando o período já acabou
        $diasDoCiclo = (int) $pagaAte->subMonthsNoOverflow($ciclo->meses())->diffInDays($pagaAte);
        $diferenca = $precoNovo - $precoAtual;

        if ($restantes <= 0 || $diasDoCiclo <= 0 || $diferenca <= 0) {
            throw self::semDias();
        }

        // Aritmética inteira: arredonda meio para cima sem passar por float.
        $valor = intdiv(2 * $diferenca * $restantes + $diasDoCiclo, 2 * $diasDoCiclo);
        if ($valor <= 0) {
            throw self::semDias();
        }

        return $valor;
    }

    private static function semDias(): BillingException
    {
        return new BillingException(
            'BILLING_TROCA_SEM_DIAS',
            'O período pago termina hoje ou a diferença é pequena demais para cobrar. Aguarde a renovação para trocar de plano.',
        );
    }
}

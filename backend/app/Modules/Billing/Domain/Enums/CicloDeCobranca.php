<?php

declare(strict_types=1);

namespace App\Modules\Billing\Domain\Enums;

use App\Modules\Billing\Domain\Exceptions\BillingException;
use App\Modules\Platform\Domain\Models\Plano;

enum CicloDeCobranca: string
{
    case Mensal = 'MENSAL';
    case Anual = 'ANUAL';

    public function meses(): int
    {
        return match ($this) {
            self::Mensal => 1,
            self::Anual => 12,
        };
    }

    /** Preço do plano para o ciclo, em centavos. Plano sem preço do ciclo não aceita o ciclo: não há valor padrão. */
    public function precoDo(Plano $plano): int
    {
        $preco = match ($this) {
            self::Mensal => $plano->preco_mensal_centavos,
            self::Anual => $plano->preco_anual_centavos,
        };
        if ($preco === null || $preco <= 0) {
            throw new BillingException('BILLING_CICLO_INDISPONIVEL', 'Este plano não tem preço para o ciclo escolhido. Escolha outro ciclo ou outro plano.');
        }

        return $preco;
    }
}

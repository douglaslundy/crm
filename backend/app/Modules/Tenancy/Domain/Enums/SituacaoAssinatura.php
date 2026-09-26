<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Domain\Enums;

enum SituacaoAssinatura: string
{
    case Pendente = 'PENDENTE';
    case Teste = 'TESTE';
    case Ativa = 'ATIVA';
    case Inadimplente = 'INADIMPLENTE';
    case Suspensa = 'SUSPENSA';
    case Cancelada = 'CANCELADA';

    /** @return list<string> */
    public static function valores(): array
    {
        return array_column(self::cases(), 'value');
    }

    /** Transições permitidas na F1 (spec F1 §4). Não há estado implícito. */
    public function podeIrPara(self $destino): bool
    {
        if ($destino === self::Cancelada) {
            return $this !== self::Cancelada;
        }

        $permitidos = match ($this) {
            self::Pendente => [self::Ativa],
            self::Teste => [self::Ativa, self::Suspensa],
            self::Ativa, self::Inadimplente => [self::Suspensa],
            self::Suspensa => [self::Ativa],
            self::Cancelada => [],
        };

        return in_array($destino, $permitidos, true);
    }

    /** Leitura continua liberada em SUSPENSA/CANCELADA: o cliente precisa baixar seus dados. */
    public function permiteEscrita(): bool
    {
        return in_array($this, [self::Teste, self::Ativa, self::Inadimplente], true);
    }
}

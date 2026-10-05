<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Domain\Enums;

enum StatusNota: string
{
    case Rascunho = 'RASCUNHO';
    case Processando = 'PROCESSANDO';
    case Autorizada = 'AUTORIZADA';
    case Rejeitada = 'REJEITADA';
    case Erro = 'ERRO';
    case Cancelada = 'CANCELADA';
    case Cancelando = 'CANCELANDO';
    case Contingencia = 'CONTINGENCIA';

    /** Rascunho, rejeitada e erro voltam a ser editáveis e podem ser reemitidas. */
    public function editavel(): bool
    {
        return in_array($this, [self::Rascunho, self::Rejeitada, self::Erro], true);
    }
}

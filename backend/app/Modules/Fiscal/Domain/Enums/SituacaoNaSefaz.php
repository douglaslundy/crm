<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Domain\Enums;

enum SituacaoNaSefaz: string
{
    case Autorizada = 'AUTORIZADA';
    case Cancelada = 'CANCELADA';
    case NaoEncontrada = 'NAO_ENCONTRADA';
    case Erro = 'ERRO';
}

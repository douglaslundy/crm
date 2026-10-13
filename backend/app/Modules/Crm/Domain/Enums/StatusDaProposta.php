<?php

declare(strict_types=1);

namespace App\Modules\Crm\Domain\Enums;

/** "Expirada" não é gravada: é calculada na leitura (Proposta::statusApresentado). */
enum StatusDaProposta: string
{
    case Rascunho = 'RASCUNHO';
    case Enviada = 'ENVIADA';
    case Aceita = 'ACEITA';
    case Recusada = 'RECUSADA';
    case Cancelada = 'CANCELADA';
}

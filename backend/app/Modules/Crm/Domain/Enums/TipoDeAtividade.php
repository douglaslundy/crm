<?php

declare(strict_types=1);

namespace App\Modules\Crm\Domain\Enums;

enum TipoDeAtividade: string
{
    case Ligacao = 'LIGACAO';
    case Reuniao = 'REUNIAO';
    case Email = 'EMAIL';
    case Whatsapp = 'WHATSAPP';
    case Tarefa = 'TAREFA';
}

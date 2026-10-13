<?php

declare(strict_types=1);

namespace App\Modules\Crm\Domain\Enums;

enum TipoDeRegistro: string
{
    case Nota = 'NOTA';
    case AtividadeConcluida = 'ATIVIDADE_CONCLUIDA';
    case NegocioCriado = 'NEGOCIO_CRIADO';
    case NegocioMovido = 'NEGOCIO_MOVIDO';
    case NegocioGanho = 'NEGOCIO_GANHO';
    case NegocioPerdido = 'NEGOCIO_PERDIDO';
}

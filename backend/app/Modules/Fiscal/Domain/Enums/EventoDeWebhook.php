<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Domain\Enums;

enum EventoDeWebhook: string
{
    case NotaAutorizada = 'nota.autorizada';
    case NotaRejeitada = 'nota.rejeitada';
    case NotaCancelada = 'nota.cancelada';
    case NotaErro = 'nota.erro';
    case NotaContingencia = 'nota.contingencia';
    case Teste = 'webhook.teste';

    /** Rascunho, processando e cancelando não geram evento. */
    public static function daNota(StatusNota $status): ?self
    {
        return match ($status) {
            StatusNota::Autorizada => self::NotaAutorizada,
            StatusNota::Rejeitada => self::NotaRejeitada,
            StatusNota::Cancelada => self::NotaCancelada,
            StatusNota::Erro => self::NotaErro,
            StatusNota::Contingencia => self::NotaContingencia,
            StatusNota::Rascunho, StatusNota::Processando, StatusNota::Cancelando => null,
        };
    }

    /** @return list<string> os eventos de nota que um endpoint pode assinar */
    public static function assinaveis(): array
    {
        return [
            self::NotaAutorizada->value, self::NotaRejeitada->value, self::NotaCancelada->value,
            self::NotaErro->value, self::NotaContingencia->value,
        ];
    }
}

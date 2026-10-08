<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Infrastructure;

use App\Modules\Fiscal\Application\Webhooks\RegistrarEntregasDeWebhook;
use App\Modules\Fiscal\Domain\Models\NotaEvento;
use Illuminate\Support\Facades\DB;
use Throwable;

/** `NotaEvento` é o único ponto onde o status de uma nota muda: aqui nascem as entregas de webhook. */
final class ObservadorDeNotaEvento
{
    public function __construct(private readonly RegistrarEntregasDeWebhook $registrar) {}

    public function created(NotaEvento $evento): void
    {
        try {
            // Savepoint: se o registro falhar no meio, nada dele fica e a transação da nota segue válida.
            DB::transaction(fn (): int => $this->registrar->executar($evento));
        } catch (Throwable $e) {
            // Avisar o integrador nunca pode derrubar a mudança de status da nota.
            report($e);
        }
    }
}

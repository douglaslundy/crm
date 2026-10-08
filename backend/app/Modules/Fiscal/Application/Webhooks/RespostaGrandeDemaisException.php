<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Application\Webhooks;

use RuntimeException;

/** O destino do webhook devolveu mais do que aceitamos ler: a tentativa é abortada e conta como falha. */
final class RespostaGrandeDemaisException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('A resposta do servidor é grande demais.');
    }
}

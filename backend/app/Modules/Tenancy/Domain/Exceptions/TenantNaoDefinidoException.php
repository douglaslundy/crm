<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Domain\Exceptions;

use RuntimeException;

final class TenantNaoDefinidoException extends RuntimeException
{
    public static function semContexto(): self
    {
        return new self('Nenhum tenant definido no contexto desta operação.');
    }

    public static function divergente(string $esperado, string $recebido): self
    {
        return new self("Registro com tenant_id {$recebido} fora do tenant do contexto ({$esperado}).");
    }

    public static function imutavel(string $original): self
    {
        return new self("O tenant_id de um registro não pode mudar (original: {$original}).");
    }
}

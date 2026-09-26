<?php

declare(strict_types=1);

namespace App\Modules\Platform\Application;

use App\Modules\Platform\Domain\Contracts\ContadorDeUso;
use App\Modules\Platform\Domain\Enums\Recurso;
use App\Modules\Platform\Domain\Exceptions\RecursoSemContadorException;

final class RegistroDeContadores
{
    /** @var array<string, ContadorDeUso> */
    private array $contadores = [];

    public function registrar(ContadorDeUso $contador): void
    {
        $this->contadores[$contador->recurso()->value] = $contador;
    }

    public function para(Recurso $recurso): ContadorDeUso
    {
        return $this->contadores[$recurso->value] ?? throw RecursoSemContadorException::para($recurso);
    }

    /** @return list<Recurso> */
    public function recursos(): array
    {
        return array_values(array_filter(Recurso::cases(), fn (Recurso $r): bool => isset($this->contadores[$r->value])));
    }
}

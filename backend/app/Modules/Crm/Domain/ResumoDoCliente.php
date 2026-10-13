<?php

declare(strict_types=1);

namespace App\Modules\Crm\Domain;

final class ResumoDoCliente
{
    public function __construct(
        public readonly string $id,
        public readonly string $nome,
        public readonly ?string $telefone,
        public readonly ?string $email,
        public readonly bool $ehLead,
    ) {}
}

<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain;

/** A chave inteira existe só aqui, na criação: o banco guarda apenas o hash. */
final readonly class ChaveGerada
{
    public function __construct(
        public string $texto,
        public string $hash,
        public string $prefixo,
        public string $ultimos4,
    ) {}
}

<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Application\Vendas;

/** O que aconteceu com cada nota da venda ao emitir. `bloqueio` só vem preenchido quando a nota não saiu. */
final class ResultadoDaVenda
{
    /** @param list<array{nota_id: string, modelo: string, status: string, bloqueio: ?array{codigo: string, mensagem: string}}> $notas */
    public function __construct(private readonly array $notas) {}

    /** @return list<array{nota_id: string, modelo: string, status: string, bloqueio: ?array{codigo: string, mensagem: string}}> */
    public function notas(): array
    {
        return $this->notas;
    }
}

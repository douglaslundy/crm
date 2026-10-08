<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Domain\Exceptions;

use App\Modules\Shared\Domain\Exceptions\ErroDeNegocio;

/** O cadastro já existe com dados diferentes dos enviados: a API não escolhe um lado nem altera o cadastro. */
final class CadastroDivergenteException extends ErroDeNegocio
{
    /** @param list<array{campo: string, enviado: mixed, cadastro: mixed}> $erros */
    public function __construct(string $descricao, private readonly array $erros)
    {
        parent::__construct("O cadastro de {$descricao} já existe com dados diferentes. Atualize o cadastro pelo endpoint próprio ou envie os mesmos dados.");
    }

    public function status(): int
    {
        return 422;
    }

    public function codigo(): string
    {
        return 'CADASTRO_DIVERGENTE';
    }

    public function extras(): array
    {
        return ['errors' => $this->erros];
    }
}

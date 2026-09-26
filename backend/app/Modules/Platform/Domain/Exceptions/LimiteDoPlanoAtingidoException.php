<?php

declare(strict_types=1);

namespace App\Modules\Platform\Domain\Exceptions;

use App\Modules\Platform\Domain\Enums\Recurso;
use App\Modules\Shared\Domain\Exceptions\ErroDeNegocio;

final class LimiteDoPlanoAtingidoException extends ErroDeNegocio
{
    private function __construct(string $mensagem, private readonly Recurso $recurso, private readonly int $limite)
    {
        parent::__construct($mensagem);
    }

    public static function para(Recurso $recurso, int $limite): self
    {
        return new self("Seu plano permite até {$limite} {$recurso->descricaoDoLimite()}.", $recurso, $limite);
    }

    public function status(): int
    {
        return 422;
    }

    public function codigo(): string
    {
        return 'LIMITE_DO_PLANO';
    }

    public function extras(): array
    {
        return ['recurso' => $this->recurso->value, 'limite' => $this->limite];
    }
}

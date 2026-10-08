<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Exceptions;

use App\Modules\Shared\Domain\Exceptions\ErroDeNegocio;

/** A mensagem de "inválida" é a mesma para chave inexistente, revogada ou de autor sem acesso: não revela qual. */
final class ChaveDeApiInvalidaException extends ErroDeNegocio
{
    private function __construct(string $mensagem, private readonly string $codigoDoErro)
    {
        parent::__construct($mensagem);
    }

    public static function ausente(): self
    {
        return new self('Informe a chave de API no cabeçalho Authorization: Bearer <chave>.', 'API_CHAVE_AUSENTE');
    }

    public static function invalida(): self
    {
        return new self('Chave de API inválida ou revogada.', 'API_CHAVE_INVALIDA');
    }

    public function status(): int
    {
        return 401;
    }

    public function codigo(): string
    {
        return $this->codigoDoErro;
    }

    public function headers(): array
    {
        return ['WWW-Authenticate' => 'Bearer'];
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Shared\Domain\Exceptions;

final class ErroDeIdempotenciaException extends ErroDeNegocio
{
    private function __construct(string $mensagem, private readonly int $statusHttp, private readonly string $codigoDoErro, private readonly ?string $notaId = null)
    {
        parent::__construct($mensagem);
    }

    public static function ausente(): self
    {
        return new self('Informe o cabeçalho Idempotency-Key: ele evita emitir a mesma nota duas vezes se você repetir a chamada.', 400, 'IDEMPOTENCY_KEY_AUSENTE');
    }

    public static function invalida(): self
    {
        return new self('Idempotency-Key deve ter de 1 a 255 caracteres visíveis, sem espaços.', 400, 'IDEMPOTENCY_KEY_INVALIDA');
    }

    public static function corpoDiferente(): self
    {
        return new self('Esta Idempotency-Key já foi usada com outro corpo ou endereço. Use uma chave nova para uma nova operação.', 409, 'IDEMPOTENCIA_CORPO_DIFERENTE');
    }

    /** Com `$notaId`, a nota já foi criada: consulte GET /notas/{id} em vez de repetir a emissão. */
    public static function emAndamento(?string $notaId = null): self
    {
        return new self('Uma requisição com esta Idempotency-Key ainda está em andamento. Aguarde e repita'.($notaId === null ? '.' : ' ou consulte a nota indicada em nota_id.'), 409, 'IDEMPOTENCIA_EM_ANDAMENTO', $notaId);
    }

    public function extras(): array
    {
        return $this->notaId === null ? [] : ['nota_id' => $this->notaId];
    }

    public function status(): int
    {
        return $this->statusHttp;
    }

    public function codigo(): string
    {
        return $this->codigoDoErro;
    }

    public function headers(): array
    {
        return $this->codigoDoErro === 'IDEMPOTENCIA_EM_ANDAMENTO' ? ['Retry-After' => '1'] : [];
    }
}

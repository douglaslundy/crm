<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Domain\Emissao;

use App\Modules\Fiscal\Domain\Enums\SituacaoNaSefaz;

final readonly class ResultadoDeConsultaNfse
{
    public function __construct(
        public SituacaoNaSefaz $situacao,
        public ?string $chave = null,
        public ?string $numeroNfse = null,
        public ?string $xmlAutorizado = null,
        public ?string $cstat = null,
        public ?string $mensagem = null,
    ) {}

    public static function autorizada(string $chave, string $numeroNfse, string $xml, string $cstat = '100'): self
    {
        return new self(SituacaoNaSefaz::Autorizada, $chave, $numeroNfse, $xml, $cstat);
    }

    public static function cancelada(?string $chave = null): self
    {
        return new self(SituacaoNaSefaz::Cancelada, $chave);
    }

    public static function naoEncontrada(): self
    {
        return new self(SituacaoNaSefaz::NaoEncontrada);
    }

    public static function erro(string $mensagem): self
    {
        return new self(SituacaoNaSefaz::Erro, mensagem: $mensagem);
    }
}

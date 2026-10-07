<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Domain\Emissao;

use App\Modules\Fiscal\Domain\Enums\StatusNota;

final readonly class ResultadoDeEmissaoNfse
{
    public function __construct(
        public StatusNota $status,
        public ?string $chave = null,
        public ?string $numeroNfse = null,
        public ?string $xmlAutorizado = null,
        public ?string $cstat = null,
        public ?string $motivo = null,
        public ?string $mensagemErro = null,
    ) {}

    public static function autorizada(string $chave, string $numeroNfse, string $xml, string $cstat = '100'): self
    {
        return new self(StatusNota::Autorizada, $chave, $numeroNfse, $xml, $cstat, 'NFS-e gerada');
    }

    public static function rejeitada(string $cstat, string $motivo): self
    {
        return new self(StatusNota::Rejeitada, cstat: $cstat, motivo: $motivo);
    }

    public static function erro(string $mensagem): self
    {
        return new self(StatusNota::Erro, mensagemErro: $mensagem);
    }
}

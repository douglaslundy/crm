<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Domain\Emissao;

use App\Modules\Fiscal\Domain\Enums\StatusNota;

/** REJEITADA é decisão da SEFAZ; ERRO é falha técnica ou incerteza. Nunca misturar. */
final readonly class ResultadoDeEmissao
{
    public function __construct(
        public StatusNota $status,
        public ?string $cstat,
        public ?string $motivo,
        public ?string $protocolo,
        public ?string $xmlAutorizado,
        public ?string $mensagemErro,
        public ?string $chaveOcupante = null,
    ) {}

    public static function autorizada(string $protocolo, string $xmlAutorizado, string $cstat = '100'): self
    {
        return new self(StatusNota::Autorizada, $cstat, 'Autorizado o uso da NF-e', $protocolo, $xmlAutorizado, null);
    }

    public static function rejeitada(string $cstat, string $motivo): self
    {
        return new self(StatusNota::Rejeitada, $cstat, $motivo, null, null, null);
    }

    public static function numeroOcupado(string $chaveOcupante, string $motivo): self
    {
        return new self(StatusNota::Rejeitada, '539', $motivo, null, null, null, $chaveOcupante);
    }

    public static function processando(): self
    {
        return new self(StatusNota::Processando, null, null, null, null, null);
    }

    public static function erro(string $mensagem): self
    {
        return new self(StatusNota::Erro, null, null, null, null, $mensagem);
    }
}

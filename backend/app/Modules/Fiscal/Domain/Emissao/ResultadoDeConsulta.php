<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Domain\Emissao;

use App\Modules\Fiscal\Domain\Enums\SituacaoNaSefaz;

final readonly class ResultadoDeConsulta
{
    /** cStat de uso denegado: 110, 301 (emitente), 302 (destinatário) e 303 (destinatário não habilitado). */
    public const DENEGADAS = ['110', '301', '302', '303'];

    public function __construct(
        public SituacaoNaSefaz $situacao,
        public ?string $protocolo = null,
        public ?string $xmlAutorizado = null,
        public ?string $cstat = null,
        public ?string $motivo = null,
    ) {}

    public static function autorizada(string $protocolo, ?string $xmlAutorizado, string $cstat = '100'): self
    {
        return new self(SituacaoNaSefaz::Autorizada, $protocolo, $xmlAutorizado, $cstat, 'Autorizado o uso da NF-e');
    }

    public static function cancelada(string $cstat = '101'): self
    {
        return new self(SituacaoNaSefaz::Cancelada, null, null, $cstat, 'Cancelamento de NF-e homologado');
    }

    public static function naoEncontrada(): self
    {
        return new self(SituacaoNaSefaz::NaoEncontrada, null, null, '217', 'NF-e não consta na base de dados da SEFAZ');
    }

    /** Uso denegado (110, 301, 302, 303): terminal. A SEFAZ consumiu o número e a nota não pode ser autorizada nem corrigida. */
    public static function denegada(string $cstat, string $motivo): self
    {
        return new self(SituacaoNaSefaz::Erro, null, null, $cstat, $motivo);
    }

    public function ehDenegada(): bool
    {
        return $this->situacao === SituacaoNaSefaz::Erro && in_array($this->cstat, self::DENEGADAS, true);
    }

    public static function erro(string $motivo): self
    {
        return new self(SituacaoNaSefaz::Erro, null, null, null, $motivo);
    }
}

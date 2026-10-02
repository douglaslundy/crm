<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Domain\Emissao;

use App\Modules\Fiscal\Domain\Enums\SituacaoNaSefaz;

final readonly class ResultadoDeConsulta
{
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

    public static function erro(string $motivo): self
    {
        return new self(SituacaoNaSefaz::Erro, null, null, null, $motivo);
    }
}

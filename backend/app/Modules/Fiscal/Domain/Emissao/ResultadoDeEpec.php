<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Domain\Emissao;

final readonly class ResultadoDeEpec
{
    public function __construct(
        public bool $registrado,
        public ?string $cstat,
        public ?string $motivo,
        public ?string $protocolo,
        public ?string $xmlEvento,
    ) {}

    public static function registrado(string $protocolo, string $xmlEvento, string $cstat = '135'): self
    {
        return new self(true, $cstat, 'Evento prévio de emissão em contingência registrado', $protocolo, $xmlEvento);
    }

    public static function recusado(string $cstat, string $motivo): self
    {
        return new self(false, $cstat, $motivo, null, null);
    }
}

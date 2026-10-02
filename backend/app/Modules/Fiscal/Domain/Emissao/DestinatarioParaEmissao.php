<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Domain\Emissao;

final readonly class DestinatarioParaEmissao
{
    public function __construct(
        public string $tipo,
        public string $documento,
        public string $nome,
        public int $indIeDest,
        public int $indFinal,
        public ?string $ie,
        public string $logradouro,
        public string $numero,
        public string $bairro,
        public string $codigoIbge,
        public string $cidade,
        public string $uf,
        public string $cep,
        public ?string $email,
    ) {}
}

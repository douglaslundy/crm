<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Domain\Emissao;

final readonly class EmitenteParaEmissao
{
    public function __construct(
        public string $cnpj,
        public string $razaoSocial,
        public ?string $nomeFantasia,
        public string $ie,
        public int $crt,
        public string $logradouro,
        public string $numero,
        public string $bairro,
        public string $codigoIbge,
        public string $cidade,
        public string $uf,
        public string $cep,
        public ?string $cnae,
    ) {}
}

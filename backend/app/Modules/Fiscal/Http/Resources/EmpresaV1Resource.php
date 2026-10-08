<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Http\Resources;

use App\Modules\Fiscal\Domain\Models\Emitente;
use App\Modules\Identity\Domain\Enums\AmbienteDaChave;
use App\Modules\Tenancy\Domain\Models\Tenant;

/**
 * Dados da empresa para o integrador: lista explícita de campos, nunca o Model inteiro. Certificado, senha,
 * CSC e tokens ficam de fora por construção (inclusive o titular do certificado).
 */
final class EmpresaV1Resource
{
    public function __construct(private readonly Tenant $tenant, private readonly ?Emitente $emitente, private readonly AmbienteDaChave $ambienteDaChave) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $emitente = $this->emitente;

        return [
            'razao_social' => $this->tenant->razao_social,
            'nome_fantasia' => $this->tenant->nome_fantasia,
            'cnpj' => $this->tenant->cnpj,
            'logradouro' => $emitente?->logradouro,
            'numero' => $emitente?->numero,
            'bairro' => $emitente?->bairro,
            'cidade' => $emitente?->cidade,
            'uf' => $emitente?->uf,
            'cep' => $emitente?->cep,
            'codigo_ibge' => $emitente?->codigo_ibge,
            'regime_tributario' => $emitente?->regime_tributario,
            'inscricao_estadual' => $emitente?->inscricao_estadual,
            'inscricao_municipal' => $emitente?->inscricao_municipal,
            'cnae' => $emitente?->cnae,
            'ambiente_fiscal' => $emitente?->ambiente_fiscal->value,
            'certificado_status' => $emitente?->certificado_status->value,
            'certificado_validade' => $emitente?->certificado_validade?->toDateString(),
            'ambiente_da_chave' => strtolower($this->ambienteDaChave->value),
        ];
    }
}

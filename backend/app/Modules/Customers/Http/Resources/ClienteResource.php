<?php

declare(strict_types=1);

namespace App\Modules\Customers\Http\Resources;

use App\Modules\Customers\Domain\Models\Cliente;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Cliente */
final class ClienteResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'tipo' => $this->tipo->value,
            'nome' => $this->nome,
            'cpf_cnpj' => $this->cpf_cnpj,
            'inscricao_estadual' => $this->inscricao_estadual,
            'ie_isento' => $this->ie_isento,
            'email' => $this->email,
            'telefone' => $this->telefone,
            'logradouro' => $this->logradouro,
            'numero' => $this->numero,
            'bairro' => $this->bairro,
            'cidade' => $this->cidade,
            'uf' => $this->uf,
            'cep' => $this->cep,
            'codigo_ibge' => $this->codigo_ibge,
            'tags' => $this->tags,
            'origem' => $this->origem,
            'estagio' => $this->estagio->value,
        ];
    }
}

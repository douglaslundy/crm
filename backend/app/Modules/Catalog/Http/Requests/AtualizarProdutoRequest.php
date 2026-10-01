<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class AtualizarProdutoRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        $regras = CriarProdutoRequest::regras();
        $regras['sku'][] = Rule::unique('produtos', 'sku')
            ->where('tenant_id', $this->user()?->tenant_id)
            ->ignore($this->route('id'));

        return $regras;
    }

    /** @return array{sku:string,nome:string,unidade:string,preco_centavos:int,gtin:?string,ncm:?string,cest:?string,origem:int|string|null,tributacao_icms:?string} */
    public function dados(): array
    {
        return [
            'sku' => $this->string('sku')->toString(),
            'nome' => trim($this->string('nome')->toString()),
            'unidade' => strtoupper($this->string('unidade')->toString()),
            'preco_centavos' => (int) $this->input('preco_centavos'),
            'gtin' => $this->filled('gtin') ? $this->string('gtin')->toString() : null,
            'ncm' => $this->filled('ncm') ? $this->string('ncm')->toString() : null,
            'cest' => $this->filled('cest') ? $this->string('cest')->toString() : null,
            'origem' => $this->input('origem'),
            'tributacao_icms' => $this->filled('tributacao_icms') ? $this->string('tributacao_icms')->toString() : null,
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class CriarProdutoRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public static function regras(): array
    {
        return [
            'sku' => ['required', 'string', 'max:60'],
            'nome' => ['required', 'string', 'max:150'],
            'unidade' => ['required', 'string', 'max:10'],
            'preco_centavos' => ['required', 'integer', 'min:0'],
            'gtin' => ['nullable', 'string', 'max:14'],
            'ncm' => ['nullable', 'string'],
            'cest' => ['nullable', 'string'],
            'origem' => ['nullable'],
            'tributacao_icms' => ['nullable', Rule::in(['NORMAL', 'ST'])],
        ];
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        $regras = self::regras();
        $regras['sku'][] = Rule::unique('produtos', 'sku')->where('tenant_id', $this->user()?->tenant_id);

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

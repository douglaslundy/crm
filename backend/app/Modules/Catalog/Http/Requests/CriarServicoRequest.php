<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class CriarServicoRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public static function regras(): array
    {
        return [
            'codigo' => ['nullable', 'string', 'max:60'],
            'nome' => ['required', 'string', 'max:150'],
            'preco_centavos' => ['required', 'integer', 'min:0'],
            'codigo_lc116' => ['nullable', 'string', 'regex:/^\d{2}\.\d{2}$/'],
            'c_trib_nac' => ['nullable', 'string', 'regex:/^\d{6}$/'],
            'codigo_municipal' => ['nullable', 'string', 'regex:/^\d{3}$/'],
            'aliquota_iss' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'nbs' => ['nullable', 'string', 'max:9'],
        ];
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        $regras = self::regras();
        $regras['codigo'][] = Rule::unique('servicos', 'codigo')->where('tenant_id', $this->user()?->tenant_id);

        return $regras;
    }

    /** @return array{codigo:?string,nome:string,preco_centavos:int,codigo_lc116:?string,c_trib_nac:?string,codigo_municipal:?string,aliquota_iss:?string,nbs:?string} */
    public function dados(): array
    {
        return [
            'codigo' => $this->filled('codigo') ? trim($this->string('codigo')->toString()) : null,
            'nome' => trim($this->string('nome')->toString()),
            'preco_centavos' => (int) $this->input('preco_centavos'),
            'codigo_lc116' => $this->filled('codigo_lc116') ? $this->string('codigo_lc116')->toString() : null,
            'c_trib_nac' => $this->filled('c_trib_nac') ? $this->string('c_trib_nac')->toString() : null,
            'codigo_municipal' => $this->filled('codigo_municipal') ? $this->string('codigo_municipal')->toString() : null,
            'aliquota_iss' => $this->filled('aliquota_iss') ? (string) $this->input('aliquota_iss') : null,
            'nbs' => $this->filled('nbs') ? $this->string('nbs')->toString() : null,
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class AtualizarServicoRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return CriarServicoRequest::regras();
    }

    /** @return array{nome:string,preco_centavos:int,codigo_lc116:?string,c_trib_nac:?string,codigo_municipal:?string,aliquota_iss:?string,nbs:?string} */
    public function dados(): array
    {
        return [
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

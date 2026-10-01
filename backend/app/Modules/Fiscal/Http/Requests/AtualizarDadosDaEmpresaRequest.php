<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class AtualizarDadosDaEmpresaRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'logradouro' => ['nullable', 'string', 'max:150'],
            'numero' => ['nullable', 'string', 'max:20'],
            'bairro' => ['nullable', 'string', 'max:100'],
            'cidade' => ['nullable', 'string', 'max:100'],
            'uf' => ['nullable', 'string', 'size:2'],
            'cep' => ['nullable', 'string'],
            'codigo_ibge' => ['nullable', 'string'],
        ];
    }

    /** @return array{logradouro:?string,numero:?string,bairro:?string,cidade:?string,uf:?string,cep:?string,codigo_ibge:?string} */
    public function dados(): array
    {
        return [
            'logradouro' => $this->filled('logradouro') ? $this->string('logradouro')->toString() : null,
            'numero' => $this->filled('numero') ? $this->string('numero')->toString() : null,
            'bairro' => $this->filled('bairro') ? $this->string('bairro')->toString() : null,
            'cidade' => $this->filled('cidade') ? $this->string('cidade')->toString() : null,
            'uf' => $this->filled('uf') ? strtoupper($this->string('uf')->toString()) : null,
            'cep' => $this->filled('cep') ? preg_replace('/\D/', '', (string) $this->input('cep')) : null,
            'codigo_ibge' => $this->filled('codigo_ibge') ? $this->string('codigo_ibge')->toString() : null,
        ];
    }
}

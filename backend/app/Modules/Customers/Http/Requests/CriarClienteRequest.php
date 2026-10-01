<?php

declare(strict_types=1);

namespace App\Modules\Customers\Http\Requests;

use App\Modules\Shared\Domain\Cnpj;
use App\Modules\Shared\Domain\Cpf;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class CriarClienteRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge([
            'cpf_cnpj' => $this->filled('cpf_cnpj') ? preg_replace('/\D/', '', (string) $this->input('cpf_cnpj')) : null,
        ]);
    }

    /** @return array<string, list<mixed>> */
    public static function regras(): array
    {
        return [
            'tipo' => ['required', Rule::in(['PF', 'PJ'])],
            'nome' => ['required', 'string', 'max:150'],
            'cpf_cnpj' => ['nullable', 'string'],
            'inscricao_estadual' => ['nullable', 'string', 'max:20'],
            'ie_isento' => ['boolean'],
            'email' => ['nullable', 'string', 'email', 'max:150'],
            'telefone' => ['nullable', 'string', 'max:20'],
            'logradouro' => ['nullable', 'string', 'max:150'],
            'numero' => ['nullable', 'string', 'max:20'],
            'bairro' => ['nullable', 'string', 'max:100'],
            'cidade' => ['nullable', 'string', 'max:100'],
            'uf' => ['nullable', 'string', 'size:2'],
            'cep' => ['nullable', 'string'],
            'codigo_ibge' => ['nullable', 'string'],
            'tags' => ['array'],
            'tags.*' => ['string', 'max:30'],
            'origem' => ['nullable', 'string', 'max:60'],
            'estagio' => ['nullable', Rule::in(['LEAD', 'CLIENTE'])],
        ];
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        $regras = self::regras();
        $regras['cpf_cnpj'][] = function (string $attribute, mixed $value, Closure $fail): void {
            $valido = $this->input('tipo') === 'PJ' ? Cnpj::tentar((string) $value) !== null : Cpf::tentar((string) $value) !== null;
            if (! $valido) {
                $fail($this->input('tipo') === 'PJ' ? 'Informe um CNPJ válido.' : 'Informe um CPF válido.');
            }
        };
        $regras['cpf_cnpj'][] = Rule::unique('clientes', 'cpf_cnpj')->where('tenant_id', $this->user()?->tenant_id);

        return $regras;
    }

    /** @return array<string, mixed> */
    public function dados(): array
    {
        return [
            'tipo' => $this->string('tipo')->toString(),
            'nome' => trim($this->string('nome')->toString()),
            'cpf_cnpj' => $this->input('cpf_cnpj'),
            'inscricao_estadual' => $this->filled('inscricao_estadual') ? $this->string('inscricao_estadual')->toString() : null,
            'ie_isento' => $this->boolean('ie_isento'),
            'email' => $this->filled('email') ? mb_strtolower($this->string('email')->toString()) : null,
            'telefone' => $this->filled('telefone') ? $this->string('telefone')->toString() : null,
            'logradouro' => $this->filled('logradouro') ? $this->string('logradouro')->toString() : null,
            'numero' => $this->filled('numero') ? $this->string('numero')->toString() : null,
            'bairro' => $this->filled('bairro') ? $this->string('bairro')->toString() : null,
            'cidade' => $this->filled('cidade') ? $this->string('cidade')->toString() : null,
            'uf' => $this->filled('uf') ? strtoupper($this->string('uf')->toString()) : null,
            'cep' => $this->filled('cep') ? preg_replace('/\D/', '', (string) $this->input('cep')) : null,
            'codigo_ibge' => $this->filled('codigo_ibge') ? $this->string('codigo_ibge')->toString() : null,
            'tags' => $this->input('tags', []),
            'origem' => $this->filled('origem') ? $this->string('origem')->toString() : null,
            'estagio' => $this->filled('estagio') ? $this->string('estagio')->toString() : 'LEAD',
        ];
    }
}

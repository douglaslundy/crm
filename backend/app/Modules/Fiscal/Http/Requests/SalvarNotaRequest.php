<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class SalvarNotaRequest extends FormRequest
{
    /**
     * `exists` com o Rule do tenant: o Global Scope não vale em regras de validação, então a checagem
     * filtra por `tenant_id` explicitamente.
     *
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $tenantId = $this->user()?->tenant_id;

        return [
            'cliente_id' => ['required', 'uuid', Rule::exists('clientes', 'id')->where('tenant_id', $tenantId)],
            'consumidor_final' => ['nullable', 'boolean'],
            'desconto_centavos' => ['required', 'integer', 'min:0'],
            'informacoes_complementares' => ['nullable', 'string', 'max:5000'],
            'itens' => ['required', 'array', 'min:1', 'max:990'],
            'itens.*.produto_id' => ['required', 'uuid', Rule::exists('produtos', 'id')->where('tenant_id', $tenantId)],
            'itens.*.quantidade' => ['required', 'string', 'regex:/^\d{1,9}(\.\d{1,3})?$/'],
            'itens.*.valor_unitario_centavos' => ['nullable', 'integer', 'min:0'],
            'pagamentos' => ['required', 'array', 'min:1'],
            'pagamentos.*.tpag' => ['required', Rule::in(['01', '03', '04', '17', '99'])],
            'pagamentos.*.xpag' => ['nullable', 'string', 'max:60'],
            'pagamentos.*.valor_centavos' => ['required', 'integer', 'min:1'],
        ];
    }

    /** @return array<string, mixed> */
    public function dados(): array
    {
        /** @var array<string, mixed> $validado */
        $validado = $this->validated();

        return $validado;
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Venda mista: itens de produto (NF-e) e de serviço (NFS-e) com um desconto geral. */
final class SalvarVendaRequest extends FormRequest
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
            'desconto_centavos' => ['required', 'integer', 'min:0'],
            // O menor limite entre as duas notas (a DPS aceita 255).
            'informacoes_complementares' => ['nullable', 'string', 'max:255'],
            'itens_produtos' => ['required', 'array', 'min:1', 'max:990'],
            'itens_produtos.*.produto_id' => ['required', 'uuid', Rule::exists('produtos', 'id')->where('tenant_id', $tenantId)],
            'itens_produtos.*.quantidade' => ['required', 'string', 'regex:/^\d{1,9}(\.\d{1,3})?$/'],
            'itens_produtos.*.valor_unitario_centavos' => ['nullable', 'integer', 'min:0'],
            'itens_servicos' => ['required', 'array', 'min:1', 'max:990'],
            'itens_servicos.*.servico_id' => ['required', 'uuid', Rule::exists('servicos', 'id')->where('tenant_id', $tenantId)],
            'itens_servicos.*.quantidade' => ['required', 'string', 'regex:/^\d{1,9}(\.\d{1,3})?$/'],
            'itens_servicos.*.valor_unitario_centavos' => ['nullable', 'integer', 'min:0'],
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

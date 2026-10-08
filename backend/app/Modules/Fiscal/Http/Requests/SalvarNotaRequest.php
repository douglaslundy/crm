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
        $modelo = (string) ($this->input('modelo') ?? 'NFE');
        $servico = $modelo === 'DPS';

        return [
            'modelo' => ['nullable', Rule::in(['NFE', 'NFCE', 'DPS'])],
            // NFC-e vende sem identificar o consumidor; NF-e e NFS-e sempre têm cliente (destinatário ou tomador).
            'cliente_id' => [
                Rule::requiredIf(fn (): bool => $modelo !== 'NFCE'),
                'nullable', 'uuid', Rule::exists('clientes', 'id')->where('tenant_id', $tenantId),
            ],
            'consumidor_final' => ['nullable', 'boolean'],
            'itens' => ['required', 'array', 'min:1', 'max:990'],
            // Nota de serviço só tem servico_id; nota de produtos só tem produto_id.
            'itens.*.produto_id' => [$servico ? 'prohibited' : 'required', 'uuid', Rule::exists('produtos', 'id')->where('tenant_id', $tenantId)],
            'itens.*.servico_id' => [$servico ? 'required' : 'prohibited', 'uuid', Rule::exists('servicos', 'id')->where('tenant_id', $tenantId)],
        ] + self::regrasComuns($servico);
    }

    /**
     * Valor, quantidade e pagamento: as mesmas regras do app e da API pública.
     *
     * @return array<string, list<mixed>>
     */
    public static function regrasComuns(bool $servico): array
    {
        return [
            'desconto_centavos' => ['required', 'integer', 'min:0'],
            'informacoes_complementares' => ['nullable', 'string', 'max:5000'],
            'itens.*.quantidade' => ['required', 'string', 'regex:/^\d{1,9}(\.\d{1,3})?$/'],
            'itens.*.valor_unitario_centavos' => ['nullable', 'integer', 'min:0'],
            'pagamentos' => [$servico ? 'prohibited' : 'required', 'array', 'min:1'],
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

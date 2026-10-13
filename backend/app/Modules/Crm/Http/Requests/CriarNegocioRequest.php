<?php

declare(strict_types=1);

namespace App\Modules\Crm\Http\Requests;

use App\Modules\Crm\Domain\Enums\TipoDeItem;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class CriarNegocioRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'titulo' => ['required', 'string', 'min:1', 'max:150'],
            'funil_id' => ['required', 'uuid'],
            'etapa_id' => ['required', 'uuid'],
            'cliente_id' => ['nullable', 'uuid', 'required_without:novo_lead'],
            'novo_lead' => ['nullable', 'array', 'required_without:cliente_id'],
            'novo_lead.nome' => ['required_with:novo_lead', 'string', 'max:150'],
            'novo_lead.telefone' => ['nullable', 'string', 'max:20'],
            'novo_lead.email' => ['nullable', 'string', 'email', 'max:150'],
            'responsavel_id' => ['nullable', 'uuid'],
            'previsao_fechamento' => ['nullable', 'date_format:Y-m-d'],
            'valor_centavos' => ['nullable', 'integer', 'min:0'],
            'itens' => ['nullable', 'array'],
            'itens.*.tipo' => ['required', Rule::enum(TipoDeItem::class)],
            'itens.*.item_id' => ['required', 'uuid'],
            'itens.*.quantidade' => ['required', 'integer', 'min:1', 'max:100000'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            $lead = $this->input('novo_lead');
            if (is_array($lead) && ($lead['telefone'] ?? '') === '' && ($lead['email'] ?? '') === '') {
                $v->errors()->add('novo_lead.telefone', 'Informe telefone ou e-mail do lead.');
            }
        });
    }
}

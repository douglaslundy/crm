<?php

declare(strict_types=1);

namespace App\Modules\Crm\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class PropostaRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'validade_dias' => ['sometimes', 'integer', 'between:1,365'],
            'desconto_centavos' => ['sometimes', 'integer', 'min:0', 'max:99999999999'],
            'observacoes' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }
}

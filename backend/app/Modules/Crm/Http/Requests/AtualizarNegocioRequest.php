<?php

declare(strict_types=1);

namespace App\Modules\Crm\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class AtualizarNegocioRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'titulo' => ['sometimes', 'string', 'min:1', 'max:150'],
            'previsao_fechamento' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'responsavel_id' => ['sometimes', 'nullable', 'uuid'],
            'valor_centavos' => ['sometimes', 'nullable', 'integer', 'min:0'],
        ];
    }
}

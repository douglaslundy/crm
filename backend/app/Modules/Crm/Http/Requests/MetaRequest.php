<?php

declare(strict_types=1);

namespace App\Modules\Crm\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class MetaRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'usuario_id' => ['required', 'uuid'],
            'mes' => ['required', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
            'unidade' => ['required', 'in:VALOR,QUANTIDADE'],
            'alvo' => ['required', 'integer', 'min:1'],
        ];
    }
}

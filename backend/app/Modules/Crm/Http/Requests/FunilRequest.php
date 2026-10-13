<?php

declare(strict_types=1);

namespace App\Modules\Crm\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class FunilRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        $criando = $this->isMethod('POST');

        return [
            'nome' => [$criando ? 'required' : 'sometimes', 'string', 'min:1', 'max:100'],
            'ativo' => ['sometimes', 'boolean'],
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Crm\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class EtapaRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        $criando = $this->isMethod('POST');

        return [
            'nome' => [$criando ? 'required' : 'sometimes', 'string', 'min:1', 'max:100'],
            'probabilidade' => [$criando ? 'required' : 'sometimes', 'integer'],
            'ativa' => ['sometimes', 'boolean'],
            'dias_para_parado' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:365'],
        ];
    }
}

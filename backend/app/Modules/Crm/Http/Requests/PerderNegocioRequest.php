<?php

declare(strict_types=1);

namespace App\Modules\Crm\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class PerderNegocioRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return ['motivo_de_perda_id' => ['required', 'uuid'], 'observacao' => ['nullable', 'string', 'max:1000']];
    }
}

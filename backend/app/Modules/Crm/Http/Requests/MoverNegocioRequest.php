<?php

declare(strict_types=1);

namespace App\Modules\Crm\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class MoverNegocioRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return ['etapa_id' => ['required', 'uuid'], 'posicao' => ['required', 'integer', 'min:0']];
    }
}

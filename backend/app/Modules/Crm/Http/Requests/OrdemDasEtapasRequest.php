<?php

declare(strict_types=1);

namespace App\Modules\Crm\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class OrdemDasEtapasRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return ['etapas' => ['required', 'array', 'min:1'], 'etapas.*' => ['uuid']];
    }
}

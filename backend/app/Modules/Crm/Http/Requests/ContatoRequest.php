<?php

declare(strict_types=1);

namespace App\Modules\Crm\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class ContatoRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'canal' => ['required', 'in:WHATSAPP,EMAIL'],
            'cliente_id' => ['required_without:negocio_id', 'nullable', 'uuid'],
            'negocio_id' => ['nullable', 'uuid'],
        ];
    }
}

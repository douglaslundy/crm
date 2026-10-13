<?php

declare(strict_types=1);

namespace App\Modules\Crm\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class NotaRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return ['texto' => ['required', 'string', 'min:1', 'max:2000']];
    }
}

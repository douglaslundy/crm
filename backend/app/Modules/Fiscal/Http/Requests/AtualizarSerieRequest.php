<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class AtualizarSerieRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'serie' => ['required', 'string', 'max:3'],
            'proximo_numero' => ['required', 'integer', 'min:1'],
        ];
    }
}

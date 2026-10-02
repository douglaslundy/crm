<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class CancelarNotaRequest extends FormRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        // 15 a 255 é a regra da SEFAZ; a checagem final (depois de sanear) é do value object Justificativa.
        return ['justificativa' => ['required', 'string', 'min:15', 'max:255']];
    }

    public function justificativa(): string
    {
        return (string) $this->validated('justificativa');
    }
}

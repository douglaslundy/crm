<?php

declare(strict_types=1);

namespace App\Modules\Crm\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class RelatorioRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        // O formato da data é validado em PeriodoDoRelatorio (devolve PERIODO_INVALIDO).
        return [
            'de' => ['nullable', 'string'],
            'ate' => ['nullable', 'string'],
            'vendedor_id' => ['nullable', 'uuid'],
            'funil_id' => ['nullable', 'uuid'],
        ];
    }
}

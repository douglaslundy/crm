<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class ConfirmarProducaoRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'confirmo' => ['required', 'accepted'],
            'numeros_iniciais' => ['sometimes', 'array'],
            'numeros_iniciais.*' => ['required', 'integer', 'min:1', 'max:999999999'],
        ];
    }

    /** @return array<string, int> modelo (valor do enum) => primeiro número de produção */
    public function numerosIniciais(): array
    {
        /** @var array<string, int> $numeros */
        $numeros = array_map('intval', (array) $this->validated('numeros_iniciais', []));

        return $numeros;
    }
}

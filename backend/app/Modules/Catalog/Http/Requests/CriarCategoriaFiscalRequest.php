<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class CriarCategoriaFiscalRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public static function regras(): array
    {
        return [
            'categoria' => ['required', 'string', 'max:60'],
            'ncm' => ['nullable', 'string'],
            'origem' => ['nullable'],
            'tributacao_icms' => ['nullable', Rule::in(['NORMAL', 'ST'])],
        ];
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        $regras = self::regras();
        $regras['categoria'][] = Rule::unique('categorias_fiscais_padrao', 'categoria')->where('tenant_id', $this->user()?->tenant_id);

        return $regras;
    }

    /** @return array{categoria:string,ncm:?string,origem:int|string|null,tributacao_icms:?string} */
    public function dados(): array
    {
        return [
            'categoria' => trim($this->string('categoria')->toString()),
            'ncm' => $this->filled('ncm') ? $this->string('ncm')->toString() : null,
            'origem' => $this->input('origem'),
            'tributacao_icms' => $this->filled('tributacao_icms') ? $this->string('tributacao_icms')->toString() : null,
        ];
    }
}

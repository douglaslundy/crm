<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class AtualizarCategoriaFiscalRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        $regras = CriarCategoriaFiscalRequest::regras();
        $regras['categoria'][] = Rule::unique('categorias_fiscais_padrao', 'categoria')
            ->where('tenant_id', $this->user()?->tenant_id)
            ->ignore($this->route('id'));

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

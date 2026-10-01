<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class AtualizarDadosFiscaisRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'regime_tributario' => ['nullable', Rule::in(['SIMPLES', 'MEI', 'NORMAL'])],
            'inscricao_estadual' => ['nullable', 'string', 'max:20'],
            'inscricao_municipal' => ['nullable', 'string', 'max:20'],
            'cnae' => ['nullable', 'string', 'max:10'],
        ];
    }

    /** @return array{regime_tributario:?string,inscricao_estadual:?string,inscricao_municipal:?string,cnae:?string} */
    public function dados(): array
    {
        return [
            'regime_tributario' => $this->filled('regime_tributario') ? $this->string('regime_tributario')->toString() : null,
            'inscricao_estadual' => $this->filled('inscricao_estadual') ? $this->string('inscricao_estadual')->toString() : null,
            'inscricao_municipal' => $this->filled('inscricao_municipal') ? $this->string('inscricao_municipal')->toString() : null,
            'cnae' => $this->filled('cnae') ? preg_replace('/\D/', '', (string) $this->input('cnae')) : null,
        ];
    }
}

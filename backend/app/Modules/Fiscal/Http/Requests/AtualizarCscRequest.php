<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class AtualizarCscRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'csc_id_homologacao' => ['nullable', 'string', 'max:10'],
            'csc_token_homologacao' => ['nullable', 'string'],
            'csc_id_producao' => ['nullable', 'string', 'max:10'],
            'csc_token_producao' => ['nullable', 'string'],
        ];
    }

    /** @return array{csc_id_homologacao:?string,csc_token_homologacao:?string,csc_id_producao:?string,csc_token_producao:?string} */
    public function dados(): array
    {
        return [
            'csc_id_homologacao' => $this->filled('csc_id_homologacao') ? $this->string('csc_id_homologacao')->toString() : null,
            'csc_token_homologacao' => $this->filled('csc_token_homologacao') ? $this->string('csc_token_homologacao')->toString() : null,
            'csc_id_producao' => $this->filled('csc_id_producao') ? $this->string('csc_id_producao')->toString() : null,
            'csc_token_producao' => $this->filled('csc_token_producao') ? $this->string('csc_token_producao')->toString() : null,
        ];
    }
}

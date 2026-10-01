<?php

declare(strict_types=1);

namespace App\Modules\Customers\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class AtualizarContatoRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'nome' => ['required', 'string', 'max:150'],
            'cargo' => ['nullable', 'string', 'max:100'],
            'email' => ['nullable', 'string', 'email', 'max:150'],
            'telefone' => ['nullable', 'string', 'max:20'],
        ];
    }

    /** @return array{nome:string,cargo:?string,email:?string,telefone:?string} */
    public function dados(): array
    {
        return [
            'nome' => trim($this->string('nome')->toString()),
            'cargo' => $this->filled('cargo') ? $this->string('cargo')->toString() : null,
            'email' => $this->filled('email') ? mb_strtolower($this->string('email')->toString()) : null,
            'telefone' => $this->filled('telefone') ? $this->string('telefone')->toString() : null,
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Requests;

use App\Modules\Identity\Domain\Enums\Papel;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ConvidarUsuarioRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge(['email' => mb_strtolower(trim((string) $this->input('email')))]);
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'nome' => ['required', 'string', 'max:120'],
            'email' => ['required', 'string', 'email', 'max:150', Rule::unique('usuarios', 'email')],
            'papel' => ['required', Rule::in(Papel::valoresDaEmpresa())],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['email.unique' => 'Este e-mail já está em uso.'];
    }

    /** @return array{nome: string, email: string, papel: string} */
    public function dados(): array
    {
        return [
            'nome' => trim($this->string('nome')->toString()),
            'email' => $this->string('email')->toString(),
            'papel' => $this->string('papel')->toString(),
        ];
    }
}

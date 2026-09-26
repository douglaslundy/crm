<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Requests;

use App\Modules\Identity\Domain\Enums\Papel;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class EditarUsuarioRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'nome' => ['required', 'string', 'max:120'],
            'papel' => ['required', Rule::in(Papel::valoresDaEmpresa())],
        ];
    }

    /** @return array{nome: string, papel: string} */
    public function dados(): array
    {
        return ['nome' => trim($this->string('nome')->toString()), 'papel' => $this->string('papel')->toString()];
    }
}

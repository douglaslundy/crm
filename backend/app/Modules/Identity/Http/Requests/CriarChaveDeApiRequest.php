<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Requests;

use App\Modules\Identity\Domain\Enums\AmbienteDaChave;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class CriarChaveDeApiRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'nome' => ['required', 'string', 'max:80'],
            'ambiente' => ['required', Rule::enum(AmbienteDaChave::class)],
        ];
    }

    public function nome(): string
    {
        return trim($this->string('nome')->toString());
    }

    public function ambiente(): AmbienteDaChave
    {
        return AmbienteDaChave::from($this->string('ambiente')->toString());
    }
}

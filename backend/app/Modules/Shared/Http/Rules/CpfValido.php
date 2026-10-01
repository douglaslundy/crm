<?php

declare(strict_types=1);

namespace App\Modules\Shared\Http\Rules;

use App\Modules\Shared\Domain\Cpf;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

final class CpfValido implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || Cpf::tentar($value) === null) {
            $fail('Informe um CPF válido.');
        }
    }
}

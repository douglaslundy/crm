<?php

declare(strict_types=1);

namespace App\Modules\Shared\Http\Rules;

use App\Modules\Shared\Domain\Cnpj;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

final class CnpjValido implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || Cnpj::tentar($value) === null) {
            $fail('Informe um CNPJ válido.');
        }
    }
}

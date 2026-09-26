<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

final class LoginRequest extends FormRequest
{
    private const MAX_TENTATIVAS = 5;
    private const MENSAGEM_GENERICA = 'E-mail ou senha incorretos.';

    protected function prepareForValidation(): void
    {
        $this->merge(['email' => mb_strtolower(trim((string) $this->input('email')))]);
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'email'    => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ];
    }

    public function autenticar(): void
    {
        $chave = $this->chaveLimite();

        if (RateLimiter::tooManyAttempts($chave, self::MAX_TENTATIVAS)) {
            $segundos = RateLimiter::availableIn($chave);
            throw ValidationException::withMessages([
                'email' => "Muitas tentativas. Tente novamente em {$segundos} segundos.",
            ]);
        }

        $credenciais = [
            'email'    => $this->string('email')->toString(),
            'password' => $this->string('password')->toString(),
            'ativo'    => true,
        ];

        if (! Auth::guard('web')->attempt($credenciais)) {
            RateLimiter::hit($chave, 15 * 60);
            throw ValidationException::withMessages(['email' => self::MENSAGEM_GENERICA]);
        }

        RateLimiter::clear($chave);
    }

    private function chaveLimite(): string
    {
        return 'login:' . $this->string('email')->toString() . '|' . $this->ip();
    }
}

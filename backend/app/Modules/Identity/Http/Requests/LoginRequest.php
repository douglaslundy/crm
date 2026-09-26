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

    private const MAX_TENTATIVAS_POR_IP = 20;

    private const MENSAGEM_GENERICA = 'E-mail ou senha incorretos.';

    protected function prepareForValidation(): void
    {
        $this->merge(['email' => mb_strtolower(trim((string) $this->input('email')))]);
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ];
    }

    public function autenticar(): void
    {
        $chaveEmail = 'login:'.$this->string('email')->toString().'|'.$this->ip();
        $chaveIp = 'login-ip:'.$this->ip();

        foreach ([$chaveEmail => self::MAX_TENTATIVAS, $chaveIp => self::MAX_TENTATIVAS_POR_IP] as $chave => $maximo) {
            if (RateLimiter::tooManyAttempts($chave, $maximo)) {
                $segundos = RateLimiter::availableIn($chave);
                throw ValidationException::withMessages([
                    'email' => "Muitas tentativas. Tente novamente em {$segundos} segundos.",
                ]);
            }
        }

        $credenciais = [
            'email' => $this->string('email')->toString(),
            'password' => $this->string('password')->toString(),
            'ativo' => true,
        ];

        if (! Auth::guard('web')->attempt($credenciais)) {
            RateLimiter::hit($chaveEmail, 15 * 60);
            // Soma entre todos os e-mails: barra quem testa senhas comuns em muitas contas.
            RateLimiter::hit($chaveIp, 60);
            throw ValidationException::withMessages(['email' => self::MENSAGEM_GENERICA]);
        }

        RateLimiter::clear($chaveEmail);
    }
}

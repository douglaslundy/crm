<?php

declare(strict_types=1);

namespace App\Modules\Platform\Http\Requests;

use App\Modules\Platform\Application\Actions\CadastrarEmpresa;
use App\Modules\Shared\Domain\Cnpj;
use App\Modules\Shared\Http\Rules\CnpjValido;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

final class CadastroRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        $cnpj = Cnpj::tentar((string) $this->input('cnpj'));
        $fantasia = trim((string) $this->input('nome_fantasia'));

        $this->merge([
            // Normalizado antes do unique: "12.abc..." e "12ABC..." são o mesmo CNPJ.
            'cnpj' => $cnpj !== null ? $cnpj->valor : $this->input('cnpj'),
            'email' => mb_strtolower(trim((string) $this->input('email'))),
            'nome_fantasia' => $fantasia === '' ? null : $fantasia,
        ]);
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'cnpj' => ['required', 'string', new CnpjValido, Rule::unique('tenants', 'cnpj')],
            'razao_social' => ['required', 'string', 'max:150'],
            'nome_fantasia' => ['nullable', 'string', 'max:150'],
            'plano_id' => ['required', 'uuid', Rule::exists('planos', 'id')->where('ativo', true)->where('visivel', true)],
            'responsavel_nome' => ['required', 'string', 'max:120'],
            'email' => ['required', 'string', 'email', 'max:150', Rule::unique('usuarios', 'email')],
            'password' => ['required', 'confirmed', Password::min(8)->mixedCase()->numbers()],
            'aceite_termos' => ['accepted'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'cnpj.unique' => CadastrarEmpresa::CNPJ_DUPLICADO,
            'email.unique' => CadastrarEmpresa::EMAIL_DUPLICADO,
            'plano_id.exists' => 'Este plano não está disponível.',
            'aceite_termos.accepted' => 'Você precisa aceitar os termos de uso.',
        ];
    }

    /**
     * @return array{cnpj: string, razao_social: string, nome_fantasia: ?string, plano_id: string,
     *     responsavel_nome: string, email: string, password: string}
     */
    public function dados(): array
    {
        return [
            'cnpj' => $this->string('cnpj')->toString(),
            'razao_social' => trim($this->string('razao_social')->toString()),
            'nome_fantasia' => $this->input('nome_fantasia'),
            'plano_id' => $this->string('plano_id')->toString(),
            'responsavel_nome' => trim($this->string('responsavel_nome')->toString()),
            'email' => $this->string('email')->toString(),
            'password' => $this->string('password')->toString(),
        ];
    }
}

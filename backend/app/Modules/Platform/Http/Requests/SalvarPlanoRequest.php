<?php

declare(strict_types=1);

namespace App\Modules\Platform\Http\Requests;

use App\Modules\Platform\Domain\Enums\Modulo;
use App\Modules\Platform\Domain\Enums\PoliticaExcedente;
use App\Modules\Platform\Domain\Enums\Recurso;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class SalvarPlanoRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'nome' => ['required', 'string', 'max:60', Rule::unique('planos', 'nome')->ignore($this->route('plano'))],
            'descricao' => ['nullable', 'string', 'max:500'],
            'preco_mensal_centavos' => ['required', 'integer', 'min:0'],
            'preco_anual_centavos' => ['nullable', 'integer', 'min:0'],
            'dias_teste' => ['required', 'integer', 'min:0', 'max:90'],
            'politica_excedente' => ['required', Rule::enum(PoliticaExcedente::class)],
            'preco_documento_excedente_centavos' => ['nullable', 'required_if:politica_excedente,COBRAR', 'integer', 'min:1'],
            'ativo' => ['required', 'boolean'],
            'visivel' => ['required', 'boolean'],
            'ordem' => ['required', 'integer', 'min:0', 'max:1000'],
            'modulos' => ['present', 'array'],
            'modulos.*' => ['distinct', Rule::enum(Modulo::class)],
            'limites' => ['present', 'array:'.implode(',', Recurso::valores())],
            'limites.*' => ['integer', 'min:-1'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'preco_documento_excedente_centavos.required_if' => 'Informe o preço por documento excedente.',
            'limites.array' => 'Há recurso desconhecido nos limites.',
            'limites.*.min' => 'Use -1 para ilimitado ou um número a partir de 0.',
        ];
    }

    /**
     * @return array{nome: string, descricao: ?string, preco_mensal_centavos: int, preco_anual_centavos: ?int,
     *     dias_teste: int, politica_excedente: string, preco_documento_excedente_centavos: ?int, ativo: bool,
     *     visivel: bool, ordem: int, modulos: list<string>, limites: array<string, int>}
     */
    public function dados(): array
    {
        /** @var array{nome: string, descricao: ?string, preco_mensal_centavos: int, preco_anual_centavos: ?int, dias_teste: int, politica_excedente: string, preco_documento_excedente_centavos: ?int, ativo: bool, visivel: bool, ordem: int, modulos: list<string>, limites: array<string, int>} $dados */
        $dados = array_merge(
            ['descricao' => null, 'preco_anual_centavos' => null, 'preco_documento_excedente_centavos' => null],
            $this->validated(),
        );

        return $dados;
    }
}

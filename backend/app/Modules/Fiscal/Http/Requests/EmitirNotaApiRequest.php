<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Http\Requests;

use App\Modules\Catalog\Http\Requests\CriarProdutoRequest;
use App\Modules\Catalog\Http\Requests\CriarServicoRequest;
use App\Modules\Customers\Http\Requests\CriarClienteRequest;
use App\Modules\Shared\Domain\Cnpj;
use App\Modules\Shared\Domain\Cpf;
use Closure;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Corpo do `POST /api/v1/notas`. Os blocos inline reusam as regras de formato dos cadastros do app, mas só o
 * identificador (SKU, código, documento) é obrigatório aqui: um item que já existe pode ir só com ele. Os campos
 * exigidos para cadastrar um item novo são cobrados pelo ResolvedorDeCadastrosInline.
 */
final class EmitirNotaApiRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        $tenantId = $this->user()?->tenant_id;
        $modelo = (string) ($this->input('modelo') ?? 'NFE');
        $servico = $modelo === 'DPS';

        $regras = [
            'modelo' => ['nullable', Rule::in(['NFE', 'NFCE', 'DPS'])],
            // NFC-e vende sem identificar o consumidor; NF-e e NFS-e sempre têm cliente (destinatário ou tomador).
            'cliente' => [Rule::requiredIf(fn (): bool => $modelo !== 'NFCE'), 'nullable', 'array'],
            'cliente.id' => ['nullable', 'uuid', Rule::exists('clientes', 'id')->where('tenant_id', $tenantId)],
            'cliente.cpf_cnpj' => ['nullable', 'string', function (string $atributo, mixed $valor, Closure $falhar): void {
                $digitos = (string) preg_replace('/\D/', '', (string) $valor);
                $tipo = (string) $this->input('cliente.tipo');
                $valido = match (true) {
                    $tipo === 'PJ' => Cnpj::tentar($digitos) !== null,
                    $tipo === 'PF' => Cpf::tentar($digitos) !== null,
                    default => Cpf::tentar($digitos) !== null || Cnpj::tentar($digitos) !== null,
                };
                if (! $valido) {
                    $falhar('Informe um CPF ou CNPJ válido.');
                }
            }],
            'consumidor_final' => ['nullable', 'boolean'],
            'itens' => ['required', 'array', 'min:1', 'max:990'],
            'itens.*.produto_id' => [$servico ? 'prohibited' : 'nullable', 'uuid', Rule::exists('produtos', 'id')->where('tenant_id', $tenantId)],
            'itens.*.servico_id' => [$servico ? 'nullable' : 'prohibited', 'uuid', Rule::exists('servicos', 'id')->where('tenant_id', $tenantId)],
            'itens.*.produto' => [$servico ? 'prohibited' : 'nullable', 'array'],
            'itens.*.servico' => [$servico ? 'nullable' : 'prohibited', 'array'],
        ] + SalvarNotaRequest::regrasComuns($servico);

        $cliente = CriarClienteRequest::regras();
        unset($cliente['cpf_cnpj'], $cliente['tags'], $cliente['tags.*'], $cliente['origem'], $cliente['estagio']);
        $regras += $this->prefixar($cliente, 'cliente.');
        $regras += $this->prefixar(CriarProdutoRequest::regras(), 'itens.*.produto.', ['sku']);
        $regras += $this->prefixar(CriarServicoRequest::regras(), 'itens.*.servico.', ['codigo']);

        return $regras;
    }

    /**
     * Cada item traz exatamente um de produto_id, produto, servico_id, servico.
     *
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validador): void {
            foreach ((array) $this->input('itens', []) as $i => $item) {
                $informados = array_filter(['produto_id', 'produto', 'servico_id', 'servico'], fn (string $campo): bool => is_array($item) && ($item[$campo] ?? null) !== null);
                if (count($informados) !== 1) {
                    $validador->errors()->add("itens.{$i}", 'Informe exatamente um entre produto_id, produto, servico_id e servico.');
                }
            }
            $cliente = $this->input('cliente');
            if (is_array($cliente) && ($cliente['id'] ?? null) !== null && ($cliente['cpf_cnpj'] ?? null) !== null) {
                $validador->errors()->add('cliente', 'Informe o id de um cliente existente ou os dados do cliente, não os dois.');
            }
            if (is_array($cliente) && ($cliente['id'] ?? null) === null && ($cliente['cpf_cnpj'] ?? null) === null) {
                $validador->errors()->add('cliente.cpf_cnpj', 'Informe o cpf_cnpj do cliente (ou o id de um cliente existente).');
            }
        }];
    }

    /** @return array<string, mixed> */
    public function dados(): array
    {
        /** @var array<string, mixed> $validado */
        $validado = $this->validated();

        return $validado;
    }

    /**
     * @param  array<string, list<mixed>>  $regras
     * @param  list<string>  $obrigatorios  campos que continuam obrigatórios; os demais viram `sometimes`
     * @return array<string, list<mixed>>
     */
    private function prefixar(array $regras, string $prefixo, array $obrigatorios = []): array
    {
        $saida = [];
        foreach ($regras as $campo => $lista) {
            $lista = array_map(fn (mixed $regra): mixed => $regra === 'required' && ! in_array($campo, $obrigatorios, true) ? 'sometimes' : $regra, $lista);
            $saida[$prefixo.$campo] = $lista;
        }
        foreach ($obrigatorios as $campo) {
            $saida[$prefixo.$campo] = ['required_with:'.rtrim($prefixo, '.'), 'string', 'max:60'];
        }

        return $saida;
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Application;

use App\Modules\Catalog\Application\Actions\CriarProduto;
use App\Modules\Catalog\Application\Actions\CriarServico;
use App\Modules\Catalog\Domain\Models\Produto;
use App\Modules\Catalog\Domain\Models\Servico;
use App\Modules\Catalog\Domain\ValidadorCamposFiscais;
use App\Modules\Customers\Application\Actions\CriarCliente;
use App\Modules\Customers\Domain\Models\Cliente;
use App\Modules\Fiscal\Domain\Exceptions\CadastroDivergenteException;
use App\Modules\Identity\Domain\Models\Usuario;
use Illuminate\Validation\ValidationException;

/**
 * Cadastros enviados dentro da nota (ERP sem sincronizar catálogo): acha pelo SKU, código ou documento ou
 * cadastra pelas Actions do app. Existindo com dados diferentes, recusa; campo omitido usa o cadastro e
 * nada do cadastro é alterado. Dado fiscal ausente cria o cadastro pendente (a emissão é que bloqueia).
 */
final class ResolvedorDeCadastrosInline
{
    public function __construct(
        private readonly CriarCliente $criarCliente,
        private readonly CriarProduto $criarProduto,
        private readonly CriarServico $criarServico,
    ) {}

    /** @param array<string, mixed> $dados */
    public function produto(Usuario $autor, array $dados, string $caminho = 'produto'): Produto
    {
        $sku = trim((string) ($dados['sku'] ?? ''));
        $enviado = $this->normalizarProduto($dados);
        $existente = Produto::query()->where('sku', $sku)->first();

        if ($existente !== null) {
            $this->conferir("SKU {$sku}", $enviado, [
                'nome' => $existente->nome, 'unidade' => $existente->unidade, 'preco_centavos' => $existente->preco_centavos,
                'gtin' => $existente->gtin, 'ncm' => $existente->ncm, 'cest' => $existente->cest,
                'origem' => $existente->origem, 'tributacao_icms' => $existente->tributacao_icms?->value,
            ]);

            return $existente;
        }

        $this->exigir($caminho, $enviado, ['nome', 'unidade', 'preco_centavos']);

        /** @var array{sku:string,nome:string,unidade:string,preco_centavos:int,gtin:?string,ncm:?string,cest:?string,origem:int|string|null,tributacao_icms:?string} $novo */
        $novo = [
            'sku' => $sku, 'ncm' => $this->textoOuNulo($dados['ncm'] ?? null), 'cest' => $this->textoOuNulo($dados['cest'] ?? null),
            'origem' => $dados['origem'] ?? null, 'tributacao_icms' => $this->textoOuNulo($dados['tributacao_icms'] ?? null),
        ] + $enviado;
        $novo += ['gtin' => null];

        return $this->criarProduto->executar($autor, $novo);
    }

    /** @param array<string, mixed> $dados */
    public function servico(Usuario $autor, array $dados, string $caminho = 'servico'): Servico
    {
        $codigo = trim((string) ($dados['codigo'] ?? ''));
        $enviado = $this->normalizarServico($dados);
        $existente = Servico::query()->where('codigo', $codigo)->first();

        if ($existente !== null) {
            $this->conferir("serviço {$codigo}", $enviado, [
                'nome' => $existente->nome, 'preco_centavos' => $existente->preco_centavos, 'codigo_lc116' => $existente->codigo_lc116,
                'c_trib_nac' => $existente->c_trib_nac, 'codigo_municipal' => $existente->codigo_municipal,
                'aliquota_iss' => $this->decimal($existente->aliquota_iss), 'nbs' => $existente->nbs,
            ]);

            return $existente;
        }

        $this->exigir($caminho, $enviado, ['nome', 'preco_centavos']);

        /** @var array{nome:string,preco_centavos:int,codigo_lc116:?string,c_trib_nac:?string,codigo_municipal:?string,aliquota_iss:?string,nbs:?string} $novo */
        $novo = $enviado + ['codigo_lc116' => null, 'c_trib_nac' => null, 'codigo_municipal' => null, 'aliquota_iss' => null, 'nbs' => null];

        return $this->criarServico->executar($autor, ['codigo' => $codigo] + $novo);
    }

    /** @param array<string, mixed> $dados */
    public function cliente(Usuario $autor, array $dados, string $caminho = 'cliente'): Cliente
    {
        $documento = $this->somenteDigitos($dados['cpf_cnpj'] ?? null) ?? '';
        $enviado = $this->normalizarCliente($dados);
        $existente = Cliente::query()->where('cpf_cnpj', $documento)->first();

        if ($existente !== null) {
            $this->conferir("cliente {$documento}", $enviado, [
                'tipo' => $existente->tipo->value, 'nome' => $existente->nome, 'inscricao_estadual' => $existente->inscricao_estadual,
                'ie_isento' => $existente->ie_isento, 'logradouro' => $existente->getAttribute('logradouro'), 'numero' => $existente->getAttribute('numero'),
                'bairro' => $existente->getAttribute('bairro'), 'cidade' => $existente->getAttribute('cidade'), 'uf' => $existente->uf,
                'cep' => $existente->getAttribute('cep'), 'codigo_ibge' => $existente->getAttribute('codigo_ibge'),
            ]);

            return $existente;
        }

        $this->exigir($caminho, $enviado, ['tipo', 'nome']);

        return $this->criarCliente->executar($autor, $enviado + [
            'cpf_cnpj' => $documento, 'ie_isento' => false, 'inscricao_estadual' => null, 'email' => $this->textoOuNulo($dados['email'] ?? null),
            'telefone' => $this->textoOuNulo($dados['telefone'] ?? null), 'logradouro' => null, 'numero' => null, 'bairro' => null,
            'cidade' => null, 'uf' => null, 'cep' => null, 'codigo_ibge' => null, 'tags' => [], 'origem' => null, 'estagio' => 'CLIENTE',
        ]);
    }

    /**
     * Só o que foi enviado (nulo = omitido). Um NCM/CEST malformado fica como veio, para divergir do cadastro em vez de
     * ser ignorado; na criação, o cadastro do app o normaliza para nulo (pendente), como no formulário.
     *
     * @param  array<string, mixed>  $dados
     * @return array<string, mixed>
     */
    private function normalizarProduto(array $dados): array
    {
        $saida = [];
        foreach (['nome', 'gtin'] as $campo) {
            if (($texto = $this->textoOuNulo($dados[$campo] ?? null)) !== null) {
                $saida[$campo] = $texto;
            }
        }
        if (($unidade = $this->textoOuNulo($dados['unidade'] ?? null)) !== null) {
            $saida['unidade'] = strtoupper($unidade);
        }
        if (isset($dados['preco_centavos'])) {
            $saida['preco_centavos'] = (int) $dados['preco_centavos'];
        }
        if (($ncm = $this->textoOuNulo($dados['ncm'] ?? null)) !== null) {
            $saida['ncm'] = ValidadorCamposFiscais::ncm($ncm) ?? $ncm;
        }
        if (($cest = $this->textoOuNulo($dados['cest'] ?? null)) !== null) {
            $saida['cest'] = ValidadorCamposFiscais::cest($cest) ?? $cest;
        }
        if (isset($dados['origem'])) {
            $saida['origem'] = ValidadorCamposFiscais::origem($dados['origem']) ?? (string) $dados['origem'];
        }
        if (($tributacao = $this->textoOuNulo($dados['tributacao_icms'] ?? null)) !== null) {
            $saida['tributacao_icms'] = strtoupper($tributacao);
        }

        return $saida;
    }

    /**
     * @param  array<string, mixed>  $dados
     * @return array<string, mixed>
     */
    private function normalizarServico(array $dados): array
    {
        $saida = [];
        foreach (['nome', 'codigo_lc116', 'c_trib_nac', 'codigo_municipal', 'nbs'] as $campo) {
            if (($texto = $this->textoOuNulo($dados[$campo] ?? null)) !== null) {
                $saida[$campo] = $texto;
            }
        }
        if (isset($dados['preco_centavos'])) {
            $saida['preco_centavos'] = (int) $dados['preco_centavos'];
        }
        if (isset($dados['aliquota_iss'])) {
            $saida['aliquota_iss'] = $this->decimal((string) $dados['aliquota_iss']);
        }

        return $saida;
    }

    /**
     * @param  array<string, mixed>  $dados
     * @return array<string, mixed>
     */
    private function normalizarCliente(array $dados): array
    {
        $saida = [];
        foreach (['tipo', 'nome', 'inscricao_estadual', 'logradouro', 'numero', 'bairro', 'cidade', 'codigo_ibge'] as $campo) {
            if (($texto = $this->textoOuNulo($dados[$campo] ?? null)) !== null) {
                $saida[$campo] = $texto;
            }
        }
        if (isset($dados['tipo'])) {
            $saida['tipo'] = strtoupper((string) $dados['tipo']);
        }
        if (($uf = $this->textoOuNulo($dados['uf'] ?? null)) !== null) {
            $saida['uf'] = strtoupper($uf);
        }
        if (($cep = $this->somenteDigitos($dados['cep'] ?? null)) !== null) {
            $saida['cep'] = $cep;
        }
        if (isset($dados['ie_isento'])) {
            $saida['ie_isento'] = (bool) $dados['ie_isento'];
        }

        return $saida;
    }

    /**
     * @param  array<string, mixed>  $enviado
     * @param  array<string, mixed>  $cadastro
     */
    private function conferir(string $descricao, array $enviado, array $cadastro): void
    {
        $erros = [];
        foreach ($enviado as $campo => $valor) {
            if ($valor !== ($cadastro[$campo] ?? null)) {
                $erros[] = ['campo' => $campo, 'enviado' => $valor, 'cadastro' => $cadastro[$campo] ?? null];
            }
        }
        if ($erros !== []) {
            throw new CadastroDivergenteException($descricao, $erros);
        }
    }

    /**
     * @param  array<string, mixed>  $enviado
     * @param  list<string>  $obrigatorios
     */
    private function exigir(string $caminho, array $enviado, array $obrigatorios): void
    {
        $faltando = [];
        foreach ($obrigatorios as $campo) {
            if (! array_key_exists($campo, $enviado)) {
                $faltando["{$caminho}.{$campo}"] = "O campo {$campo} é obrigatório para cadastrar um item novo.";
            }
        }
        if ($faltando !== []) {
            throw ValidationException::withMessages($faltando);
        }
    }

    private function textoOuNulo(mixed $valor): ?string
    {
        if ($valor === null) {
            return null;
        }
        $texto = trim((string) $valor);

        return $texto === '' ? null : $texto;
    }

    private function somenteDigitos(mixed $valor): ?string
    {
        $texto = $this->textoOuNulo($valor);
        $digitos = $texto === null ? '' : (string) preg_replace('/\D/', '', $texto);

        return $digitos === '' ? null : $digitos;
    }

    private function decimal(?string $valor): ?string
    {
        return $valor === null ? null : number_format((float) $valor, 2, '.', '');
    }
}

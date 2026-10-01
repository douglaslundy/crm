<?php

declare(strict_types=1);

namespace App\Modules\Customers\Application\Actions;

use App\Modules\Customers\Domain\Models\Cliente;
use App\Modules\Customers\Http\Requests\CriarClienteRequest;
use App\Modules\Identity\Domain\Models\Usuario;
use App\Modules\Shared\Domain\Cnpj;
use App\Modules\Shared\Domain\Cpf;
use App\Modules\Shared\Domain\Exceptions\ErroDeImportacao;
use App\Modules\Shared\Infrastructure\ImportadorCsv;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;

final class ImportarClientesCsv
{
    public function __construct(
        private readonly ImportadorCsv $importador,
        private readonly CriarCliente $criar,
        private readonly AtualizarCliente $atualizar,
    ) {}

    /** @return array{criados: int, atualizados: int, erros: list<array{linha: int, motivo: string}>} */
    public function executar(Usuario $autor, UploadedFile $arquivo): array
    {
        $criados = 0;
        $atualizados = 0;

        $resultado = $this->importador->importar($arquivo, function (array $linha) use ($autor, &$criados, &$atualizados): void {
            $dados = $this->validar($linha);
            $existente = $dados['cpf_cnpj'] !== null
                ? Cliente::query()->where('cpf_cnpj', $dados['cpf_cnpj'])->first()
                : null;

            if ($existente !== null) {
                $this->atualizar->executar($existente, $dados);
                $atualizados++;

                return;
            }

            $this->criar->executar($autor, $dados);
            $criados++;
        });

        return ['criados' => $criados, 'atualizados' => $atualizados, 'erros' => $resultado['erros']];
    }

    /**
     * @param  array<string, ?string>  $linha
     * @return array<string, mixed>
     */
    private function validar(array $linha): array
    {
        $validador = Validator::make($linha, CriarClienteRequest::regras());
        if ($validador->fails()) {
            throw new ErroDeImportacao($validador->errors()->first());
        }

        $tipo = (string) ($linha['tipo'] ?? '');
        $cpfCnpj = $linha['cpf_cnpj'] !== null ? preg_replace('/\D/', '', $linha['cpf_cnpj']) : null;
        if ($cpfCnpj !== null) {
            $valido = $tipo === 'PJ' ? Cnpj::tentar($cpfCnpj) !== null : Cpf::tentar($cpfCnpj) !== null;
            if (! $valido) {
                throw new ErroDeImportacao($tipo === 'PJ' ? 'CNPJ inválido.' : 'CPF inválido.');
            }
        }

        return [
            'tipo' => $tipo,
            'nome' => trim((string) $linha['nome']),
            'cpf_cnpj' => $cpfCnpj,
            'inscricao_estadual' => $linha['inscricao_estadual'] ?? null,
            'ie_isento' => filter_var($linha['ie_isento'] ?? false, FILTER_VALIDATE_BOOL),
            'email' => $linha['email'] ?? null,
            'telefone' => $linha['telefone'] ?? null,
            'logradouro' => $linha['logradouro'] ?? null,
            'numero' => $linha['numero'] ?? null,
            'bairro' => $linha['bairro'] ?? null,
            'cidade' => $linha['cidade'] ?? null,
            'uf' => $linha['uf'] ?? null,
            'cep' => $linha['cep'] ?? null,
            'codigo_ibge' => $linha['codigo_ibge'] ?? null,
            'tags' => [],
            'origem' => $linha['origem'] ?? null,
            'estagio' => $linha['estagio'] ?? 'LEAD',
        ];
    }
}

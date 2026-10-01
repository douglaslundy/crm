<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application\Actions;

use App\Modules\Catalog\Domain\Models\Produto;
use App\Modules\Catalog\Http\Requests\CriarProdutoRequest;
use App\Modules\Identity\Domain\Models\Usuario;
use App\Modules\Shared\Domain\Exceptions\ErroDeImportacao;
use App\Modules\Shared\Infrastructure\ImportadorCsv;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;

final class ImportarProdutosCsv
{
    public function __construct(
        private readonly ImportadorCsv $importador,
        private readonly CriarProduto $criar,
        private readonly AtualizarProduto $atualizar,
    ) {}

    /** @return array{criados: int, atualizados: int, erros: list<array{linha: int, motivo: string}>} */
    public function executar(Usuario $autor, UploadedFile $arquivo): array
    {
        $criados = 0;
        $atualizados = 0;

        $resultado = $this->importador->importar($arquivo, function (array $linha) use ($autor, &$criados, &$atualizados): void {
            $dados = $this->validar($linha);
            $existente = Produto::query()->where('sku', $dados['sku'])->first();

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
        $validador = Validator::make($linha, CriarProdutoRequest::regras());
        if ($validador->fails()) {
            throw new ErroDeImportacao($validador->errors()->first());
        }

        return [
            'sku' => (string) $linha['sku'],
            'nome' => trim((string) $linha['nome']),
            'unidade' => strtoupper((string) $linha['unidade']),
            'preco_centavos' => (int) $linha['preco_centavos'],
            'gtin' => $linha['gtin'] ?? null,
            'ncm' => $linha['ncm'] ?? null,
            'cest' => $linha['cest'] ?? null,
            'origem' => $linha['origem'] ?? null,
            'tributacao_icms' => $linha['tributacao_icms'] ?? null,
        ];
    }
}

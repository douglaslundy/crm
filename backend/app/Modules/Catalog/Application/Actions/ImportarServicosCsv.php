<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Application\Actions;

use App\Modules\Catalog\Domain\Models\Servico;
use App\Modules\Catalog\Http\Requests\CriarServicoRequest;
use App\Modules\Identity\Domain\Models\Usuario;
use App\Modules\Shared\Domain\Exceptions\ErroDeImportacao;
use App\Modules\Shared\Infrastructure\ImportadorCsv;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;

final class ImportarServicosCsv
{
    public function __construct(
        private readonly ImportadorCsv $importador,
        private readonly CriarServico $criar,
        private readonly AtualizarServico $atualizar,
    ) {}

    /** @return array{criados: int, atualizados: int, erros: list<array{linha: int, motivo: string}>} */
    public function executar(Usuario $autor, UploadedFile $arquivo): array
    {
        $criados = 0;
        $atualizados = 0;

        $resultado = $this->importador->importar($arquivo, function (array $linha) use ($autor, &$criados, &$atualizados): void {
            $dados = $this->validar($linha);
            $existente = Servico::query()->where('nome', $dados['nome'])->first();

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
        $validador = Validator::make($linha, CriarServicoRequest::regras());
        if ($validador->fails()) {
            throw new ErroDeImportacao($validador->errors()->first());
        }

        return [
            'nome' => trim((string) $linha['nome']),
            'preco_centavos' => (int) $linha['preco_centavos'],
            'codigo_lc116' => $linha['codigo_lc116'] ?? null,
            'c_trib_nac' => $linha['c_trib_nac'] ?? null,
            'codigo_municipal' => $linha['codigo_municipal'] ?? null,
            'aliquota_iss' => $linha['aliquota_iss'] ?? null,
            'nbs' => $linha['nbs'] ?? null,
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Shared\Infrastructure;

use App\Modules\Shared\Domain\Exceptions\ErroDeImportacao;
use App\Modules\Shared\Domain\Exceptions\ErroDeNegocio;
use Illuminate\Http\UploadedFile;

/** Parser de CSV tolerante a Windows-1252 (Excel), BOM e linhas malformadas. */
final class ImportadorCsv
{
    /**
     * @param  callable(array<string, ?string>, int): void  $processarLinha
     * @return array{processados: int, erros: list<array{linha: int, motivo: string}>}
     */
    public function importar(UploadedFile $arquivo, callable $processarLinha): array
    {
        $conteudo = (string) $arquivo->get();
        if (! mb_check_encoding($conteudo, 'UTF-8')) {
            $conteudo = mb_convert_encoding($conteudo, 'UTF-8', 'Windows-1252');
        }
        $conteudo = preg_replace('/^\xEF\xBB\xBF/', '', $conteudo) ?? $conteudo;

        $linhas = array_values(array_filter(
            preg_split('/\r\n|\r|\n/', $conteudo) ?: [],
            fn (string $l): bool => trim($l) !== '',
        ));
        if ($linhas === []) {
            return ['processados' => 0, 'erros' => []];
        }

        $cabecalho = str_getcsv(array_shift($linhas));
        $processados = 0;
        $erros = [];

        foreach ($linhas as $i => $linhaCrua) {
            $numero = $i + 2; // +1 pelo cabeçalho, +1 porque a planilha começa em 1
            $valores = str_getcsv($linhaCrua);
            $valores = array_pad(array_slice($valores, 0, count($cabecalho)), count($cabecalho), '');
            /** @var array<string, ?string> $linha */
            $linha = array_map(static fn (?string $v): ?string => $v === '' ? null : $v, array_combine($cabecalho, $valores));

            try {
                $processarLinha($linha, $numero);
                $processados++;
            } catch (ErroDeImportacao|ErroDeNegocio $e) {
                $erros[] = ['linha' => $numero, 'motivo' => $e->getMessage()];
            }
        }

        return ['processados' => $processados, 'erros' => $erros];
    }
}

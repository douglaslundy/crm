<?php

declare(strict_types=1);

namespace Tests\Unit\Shared;

use App\Modules\Shared\Domain\Exceptions\ErroDeImportacao;
use App\Modules\Shared\Infrastructure\ImportadorCsv;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class ImportadorCsvTest extends TestCase
{
    public function test_converte_windows1252_para_utf8(): void
    {
        $conteudo = mb_convert_encoding("nome\nJosé da Silva\n", 'Windows-1252', 'UTF-8');
        $arquivo = UploadedFile::fake()->createWithContent('a.csv', $conteudo);
        $recebidas = [];

        app(ImportadorCsv::class)->importar($arquivo, function (array $linha) use (&$recebidas): void {
            $recebidas[] = $linha;
        });

        $this->assertSame('José da Silva', $recebidas[0]['nome']);
    }

    public function test_celula_vazia_vira_null(): void
    {
        $arquivo = UploadedFile::fake()->createWithContent('a.csv', "nome,email\nAna,\n");
        $recebidas = [];

        app(ImportadorCsv::class)->importar($arquivo, function (array $linha) use (&$recebidas): void {
            $recebidas[] = $linha;
        });

        $this->assertSame('Ana', $recebidas[0]['nome']);
        $this->assertNull($recebidas[0]['email']);
    }

    public function test_linha_invalida_vira_erro_sem_derrubar_o_lote(): void
    {
        $arquivo = UploadedFile::fake()->createWithContent('a.csv', "nome\nA\nB\nC\n");

        $resultado = app(ImportadorCsv::class)->importar($arquivo, function (array $linha): void {
            if ($linha['nome'] === 'B') {
                throw new ErroDeImportacao('nome inválido');
            }
        });

        $this->assertSame(2, $resultado['processados']);
        $this->assertSame([['linha' => 3, 'motivo' => 'nome inválido']], $resultado['erros']);
    }

    public function test_remove_bom_do_inicio_do_arquivo(): void
    {
        $arquivo = UploadedFile::fake()->createWithContent('a.csv', "\xEF\xBB\xBFnome\nAna\n");
        $recebidas = [];

        app(ImportadorCsv::class)->importar($arquivo, function (array $linha) use (&$recebidas): void {
            $recebidas[] = $linha;
        });

        $this->assertArrayHasKey('nome', $recebidas[0]);
    }
}

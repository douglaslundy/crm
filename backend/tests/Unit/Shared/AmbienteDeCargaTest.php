<?php

declare(strict_types=1);

namespace Tests\Unit\Shared;

use App\Modules\Shared\Domain\AmbienteDeCarga;
use RuntimeException;
use Tests\TestCase;

class AmbienteDeCargaTest extends TestCase
{
    public function test_so_o_valor_exato_staging_libera(): void
    {
        foreach ([['staging', true], ['producao', false], ['Staging', false], ['', false], [null, false]] as [$valor, $esperado]) {
            config(['app.ambiente' => $valor]);
            $this->assertSame($esperado, AmbienteDeCarga::permitido(), 'valor: '.var_export($valor, true));
        }
    }

    public function test_exigir_lanca_fora_do_staging(): void
    {
        config(['app.ambiente' => 'producao']);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('carga:semear só roda com APP_AMBIENTE=staging.');
        AmbienteDeCarga::exigir('carga:semear');
    }

    public function test_padrao_sem_variavel_e_producao(): void
    {
        // Nenhum .env de teste define APP_AMBIENTE: o padrão do config tem que bloquear.
        $this->assertSame('producao', config('app.ambiente'));
    }
}

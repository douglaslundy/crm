<?php

declare(strict_types=1);

namespace Tests\Unit\Shared;

use App\Modules\Shared\Domain\Cnpj;
use App\Modules\Shared\Domain\Exceptions\CnpjInvalidoException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class CnpjTest extends TestCase
{
    public function test_numerico_valido_com_mascara(): void
    {
        $cnpj = Cnpj::de('11.222.333/0001-81');

        $this->assertSame('11222333000181', $cnpj->valor);
        $this->assertSame('11.222.333/0001-81', $cnpj->formatado());
    }

    public function test_alfanumerico_do_exemplo_oficial_da_receita(): void
    {
        // Exemplo da Receita Federal (IN RFB 2.229/2024): 12.ABC.345/01DE-35.
        $this->assertSame('12ABC34501DE35', Cnpj::de('12.ABC.345/01DE-35')->valor);
    }

    public function test_alfanumerico_em_minusculas_e_normalizado(): void
    {
        $this->assertSame('12ABC34501DE35', Cnpj::de('12.abc.345/01de-35')->valor);
    }

    /** @return array<string, array{string}> */
    public static function invalidos(): array
    {
        return [
            'dv errado' => ['11222333000182'],
            'todos iguais' => ['00000000000000'],
            'curto' => ['1122233300018'],
            'longo' => ['112223330001811'],
            'letra no dv' => ['12ABC34501DE3A'],
            'símbolo' => ['12ABC34501D*35'],
            'vazio' => [''],
        ];
    }

    #[DataProvider('invalidos')]
    public function test_invalidos_lancam_excecao(string $entrada): void
    {
        $this->expectException(CnpjInvalidoException::class);
        Cnpj::de($entrada);
    }

    public function test_tentar_devolve_null_quando_invalido(): void
    {
        $this->assertNull(Cnpj::tentar('11222333000182'));
        $this->assertNotNull(Cnpj::tentar('11222333000181'));
    }
}

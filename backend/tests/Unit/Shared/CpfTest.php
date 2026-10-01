<?php

declare(strict_types=1);

namespace Tests\Unit\Shared;

use App\Modules\Shared\Domain\Cpf;
use App\Modules\Shared\Domain\Exceptions\CpfInvalidoException;
use PHPUnit\Framework\TestCase;

class CpfTest extends TestCase
{
    public function test_aceita_cpf_valido_com_mascara(): void
    {
        $cpf = Cpf::de('111.444.777-35');

        $this->assertSame('11144477735', (string) $cpf);
        $this->assertSame('111.444.777-35', $cpf->formatado());
    }

    public function test_aceita_cpf_valido_sem_mascara(): void
    {
        $this->assertSame('11144477735', (string) Cpf::de('11144477735'));
    }

    public function test_rejeita_digito_verificador_errado(): void
    {
        $this->expectException(CpfInvalidoException::class);

        Cpf::de('111.444.777-34');
    }

    public function test_rejeita_todos_os_digitos_iguais(): void
    {
        $this->expectException(CpfInvalidoException::class);

        Cpf::de('111.111.111-11');
    }

    public function test_rejeita_tamanho_errado(): void
    {
        $this->expectException(CpfInvalidoException::class);

        Cpf::de('123');
    }

    public function test_tentar_devolve_null_em_vez_de_lancar(): void
    {
        $this->assertNull(Cpf::tentar('123'));
        $this->assertSame('11144477735', (string) Cpf::tentar('111.444.777-35'));
    }
}

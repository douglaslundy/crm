<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use App\Modules\Identity\Domain\Enums\Papel;
use App\Modules\Identity\Domain\Models\Usuario;
use Tests\TestCase;

class UsuarioTest extends TestCase
{
    public function test_tenant_id_e_papel_nao_sao_atribuiveis_em_massa(): void
    {
        $usuario = new Usuario([
            'nome' => 'Ana', 'email' => 'ana@x.com',
            'tenant_id' => 'qualquer', 'papel' => Papel::Superadmin,
        ]);

        $this->assertSame('Ana', $usuario->nome);
        $this->assertArrayNotHasKey('tenant_id', $usuario->getAttributes());
        $this->assertArrayNotHasKey('papel', $usuario->getAttributes());
    }
}

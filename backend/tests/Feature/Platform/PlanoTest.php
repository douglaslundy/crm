<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Modules\Platform\Domain\Enums\Modulo;
use App\Modules\Platform\Domain\Enums\Recurso;
use App\Modules\Platform\Domain\Models\Plano;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlanoTest extends TestCase
{
    use RefreshDatabase;

    public function test_recurso_sem_linha_vale_zero(): void
    {
        $plano = Plano::factory()->create();

        $this->assertSame(0, $plano->limiteDe(Recurso::Usuarios));
    }

    public function test_menos_um_e_preservado_como_ilimitado(): void
    {
        $plano = Plano::factory()->comLimites([Recurso::Usuarios->value => -1, Recurso::Clientes->value => 500])->create();

        $this->assertSame(Plano::ILIMITADO, $plano->limiteDe(Recurso::Usuarios));
        $this->assertSame(500, $plano->limiteDe(Recurso::Clientes));
    }

    public function test_tem_modulo(): void
    {
        $plano = Plano::factory()->comModulos(Modulo::FiscalNfe, Modulo::Crm)->create();

        $this->assertTrue($plano->temModulo(Modulo::Crm));
        $this->assertFalse($plano->temModulo(Modulo::FiscalNfse));
    }

    public function test_modulo_duplicado_no_mesmo_plano_e_recusado_pelo_banco(): void
    {
        $plano = Plano::factory()->comModulos(Modulo::Crm)->create();

        $this->expectException(UniqueConstraintViolationException::class);
        $plano->modulos()->create(['modulo' => Modulo::Crm]);
    }
}

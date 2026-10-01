<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Modules\Identity\Domain\Models\Usuario;
use App\Modules\Platform\Application\EntitlementService;
use App\Modules\Platform\Domain\Enums\Modulo;
use App\Modules\Platform\Domain\Enums\Recurso;
use App\Modules\Platform\Domain\Exceptions\LimiteDoPlanoAtingidoException;
use App\Modules\Platform\Domain\Exceptions\ModuloNaoContratadoException;
use App\Modules\Platform\Domain\Exceptions\RecursoSemContadorException;
use App\Modules\Platform\Domain\Models\Plano;
use App\Modules\Tenancy\Domain\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EntitlementServiceTest extends TestCase
{
    use RefreshDatabase;

    private function servico(): EntitlementService
    {
        return app(EntitlementService::class);
    }

    /** @param array<string, int> $limites */
    private function tenantCom(array $limites, int $ativos = 0, int $inativos = 0): Tenant
    {
        $tenant = Tenant::factory()->for(Plano::factory()->comLimites($limites))->create();
        Usuario::factory()->count($ativos)->for($tenant)->create();
        Usuario::factory()->count($inativos)->for($tenant)->create(['ativo' => false]);

        return $tenant;
    }

    public function test_recurso_sem_linha_vale_zero(): void
    {
        $tenant = $this->tenantCom([]);

        $this->assertSame(0, $this->servico()->limite($tenant, Recurso::Usuarios));
        try {
            $this->servico()->garantirCapacidade($tenant, Recurso::Usuarios);
            $this->fail('Deveria barrar.');
        } catch (LimiteDoPlanoAtingidoException $e) {
            $this->assertSame('Seu plano permite até 0 usuários ativos.', $e->getMessage());
            $this->assertSame('LIMITE_DO_PLANO', $e->codigo());
            $this->assertSame(422, $e->status());
            $this->assertSame(['recurso' => 'USUARIOS', 'limite' => 0], $e->extras());
        }
    }

    public function test_menos_um_e_ilimitado(): void
    {
        $tenant = $this->tenantCom([Recurso::Usuarios->value => -1], ativos: 5);

        $this->servico()->garantirCapacidade($tenant, Recurso::Usuarios, 100);
        $this->addToAssertionCount(1);
    }

    public function test_limite_conta_so_usuarios_ativos(): void
    {
        $tenant = $this->tenantCom([Recurso::Usuarios->value => 2], ativos: 1, inativos: 3);

        $this->assertSame(1, $this->servico()->uso($tenant, Recurso::Usuarios));
        $this->servico()->garantirCapacidade($tenant, Recurso::Usuarios);

        Usuario::factory()->for($tenant)->create();
        $this->expectException(LimiteDoPlanoAtingidoException::class);
        $this->servico()->garantirCapacidade($tenant, Recurso::Usuarios);
    }

    public function test_recurso_sem_contador_lanca_em_vez_de_devolver_zero(): void
    {
        // DocumentosMes só ganha contador na F3 (emissão de documentos fiscais).
        $tenant = $this->tenantCom([Recurso::DocumentosMes->value => 10]);

        $this->expectException(RecursoSemContadorException::class);
        $this->servico()->uso($tenant, Recurso::DocumentosMes);
    }

    public function test_tenant_sem_plano_nao_tem_modulo_nem_limite(): void
    {
        $tenant = Tenant::factory()->create(['plano_id' => null]);

        $this->assertFalse($this->servico()->temModulo($tenant, Modulo::Crm));
        $this->assertSame(0, $this->servico()->limite($tenant, Recurso::Usuarios));
    }

    public function test_garantir_modulo_nao_contratado(): void
    {
        $tenant = Tenant::factory()->for(Plano::factory()->comModulos(Modulo::FiscalNfe))->create();

        $this->servico()->garantirModulo($tenant, Modulo::FiscalNfe);
        try {
            $this->servico()->garantirModulo($tenant, Modulo::FiscalNfse);
            $this->fail('Deveria barrar.');
        } catch (ModuloNaoContratadoException $e) {
            $this->assertSame('MODULO_NAO_CONTRATADO', $e->codigo());
            $this->assertSame(403, $e->status());
            $this->assertSame('O módulo NFS-e não faz parte do seu plano.', $e->getMessage());
        }
    }

    public function test_consumo_e_excessos(): void
    {
        $tenant = $this->tenantCom([Recurso::Usuarios->value => 5], ativos: 3);
        $menor = Plano::factory()->comLimites([Recurso::Usuarios->value => 2])->create();
        $ilimitado = Plano::factory()->comLimites([Recurso::Usuarios->value => -1])->create();

        // consumo() lista todo recurso com contador registrado; outros módulos (F2+) registram os seus.
        $this->assertContains(['recurso' => 'USUARIOS', 'uso' => 3, 'limite' => 5], $this->servico()->consumo($tenant));
        $this->assertSame([['recurso' => 'USUARIOS', 'uso' => 3, 'limite' => 2]], $this->servico()->excessos($tenant, $menor));
        $this->assertSame([], $this->servico()->excessos($tenant, $ilimitado));
    }
}

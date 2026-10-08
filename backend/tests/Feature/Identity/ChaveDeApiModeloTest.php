<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use App\Modules\Identity\Domain\Enums\AmbienteDaChave;
use App\Modules\Identity\Domain\Models\ChaveDeApi;
use App\Modules\Identity\Domain\Models\Usuario;
use App\Modules\Tenancy\Domain\Models\Tenant;
use App\Modules\Tenancy\Domain\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ChaveDeApiModeloTest extends TestCase
{
    use RefreshDatabase;

    public function test_cria_por_factory_com_tenant_e_usuario(): void
    {
        $tenant = Tenant::factory()->create();
        app(TenantContext::class)->set($tenant->id);
        $usuario = Usuario::factory()->create();

        $chave = ChaveDeApi::factory()->create(['usuario_id' => $usuario->id]);

        $this->assertSame($tenant->id, $chave->tenant_id);
        $this->assertSame($usuario->id, $chave->usuario->id);
        $this->assertSame(AmbienteDaChave::Live, $chave->ambiente);
        $this->assertFalse($chave->estaRevogada());
    }

    public function test_hash_nao_aparece_na_serializacao(): void
    {
        $tenant = Tenant::factory()->create();
        app(TenantContext::class)->set($tenant->id);
        $chave = ChaveDeApi::factory()->create(['usuario_id' => Usuario::factory()->create()->id]);

        $this->assertArrayNotHasKey('hash', $chave->toArray());
        $this->assertStringNotContainsString($chave->hash, $chave->toJson());
    }

    public function test_chaves_sao_isoladas_por_tenant(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        $contexto = app(TenantContext::class);

        $contexto->set($tenantA->id);
        $chave = ChaveDeApi::factory()->create(['usuario_id' => Usuario::factory()->create()->id]);

        $contexto->set($tenantB->id);
        $this->assertSame(0, ChaveDeApi::query()->count());
        $this->assertNull(ChaveDeApi::query()->find($chave->id));
    }

    public function test_hash_e_unico(): void
    {
        $tenant = Tenant::factory()->create();
        app(TenantContext::class)->set($tenant->id);
        $usuarioId = Usuario::factory()->create()->id;
        $primeira = ChaveDeApi::factory()->create(['usuario_id' => $usuarioId]);

        $this->expectException(QueryException::class);
        ChaveDeApi::factory()->create(['usuario_id' => $usuarioId, 'hash' => $primeira->hash]);
    }

    public function test_esta_revogada_segue_revogada_em(): void
    {
        $tenant = Tenant::factory()->create();
        app(TenantContext::class)->set($tenant->id);
        $chave = ChaveDeApi::factory()->create(['usuario_id' => Usuario::factory()->create()->id]);

        $chave->forceFill(['revogada_em' => now()])->save();

        $this->assertTrue($chave->fresh()?->estaRevogada());
    }
}

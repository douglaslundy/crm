<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use App\Modules\Identity\Application\Actions\CriarChaveDeApi;
use App\Modules\Identity\Application\Actions\ListarChavesDeApi;
use App\Modules\Identity\Application\Actions\RevogarChaveDeApi;
use App\Modules\Identity\Domain\Enums\AmbienteDaChave;
use App\Modules\Identity\Domain\Enums\Papel;
use App\Modules\Identity\Domain\Exceptions\LimiteDeChavesAtingidoException;
use App\Modules\Identity\Domain\GeradorDeChaveDeApi;
use App\Modules\Identity\Domain\Models\ChaveDeApi;
use App\Modules\Identity\Domain\Models\Usuario;
use App\Modules\Identity\Domain\ResultadoDaChave;
use App\Modules\Platform\Domain\Enums\Modulo;
use App\Modules\Platform\Domain\Exceptions\ModuloNaoContratadoException;
use App\Modules\Platform\Domain\Models\Plano;
use App\Modules\Shared\Domain\Exceptions\AcessoNegadoException;
use App\Modules\Tenancy\Domain\Models\Tenant;
use App\Modules\Tenancy\Domain\TenantContext;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class ChavesDeApiActionsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Usuario $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::factory()->for(Plano::factory()->comModulos(Modulo::Api))->create();
        app(TenantContext::class)->set($this->tenant->id);
        $this->admin = Usuario::factory()->for($this->tenant)->create(['papel' => Papel::Admin]);
    }

    private function criar(string $nome = 'ERP', AmbienteDaChave $ambiente = AmbienteDaChave::Live, ?Usuario $autor = null): ResultadoDaChave
    {
        return app(CriarChaveDeApi::class)->executar($autor ?? $this->admin, $nome, $ambiente);
    }

    private function chaveAtiva(): ChaveDeApi
    {
        $gerada = (new GeradorDeChaveDeApi)->gerar(AmbienteDaChave::Live);

        return ChaveDeApi::factory()->create(['usuario_id' => $this->admin->id, 'hash' => $gerada->hash, 'prefixo' => $gerada->prefixo, 'ultimos4' => $gerada->ultimos4]);
    }

    public function test_criar_devolve_o_texto_e_grava_so_o_hash(): void
    {
        $resultado = $this->criar('ERP da loja', AmbienteDaChave::Test);

        $this->assertMatchesRegularExpression('/^test_[A-Za-z0-9]{40}$/', $resultado->texto);
        $chave = $resultado->modelo->fresh();
        $this->assertNotNull($chave);
        $this->assertSame(hash('sha256', $resultado->texto), $chave->hash);
        $this->assertSame(substr($resultado->texto, 0, 12), $chave->prefixo);
        $this->assertSame(substr($resultado->texto, -4), $chave->ultimos4);
        $this->assertSame($this->admin->id, $chave->usuario_id);
        $this->assertSame($this->tenant->id, $chave->tenant_id);
        $this->assertSame(AmbienteDaChave::Test, $chave->ambiente);
        $this->assertSame('ERP da loja', $chave->nome);
        $this->assertNull($chave->revogada_em);
    }

    public function test_auditoria_nao_guarda_o_texto_da_chave(): void
    {
        $resultado = $this->criar();
        app(RevogarChaveDeApi::class)->executar($this->admin, $resultado->modelo->id);

        $registros = Activity::query()->where('log_name', 'api')->get();
        $this->assertCount(2, $registros);
        foreach ($registros as $registro) {
            $this->assertStringNotContainsString($resultado->texto, (string) json_encode($registro->toArray()));
            $this->assertStringNotContainsString(hash('sha256', $resultado->texto), (string) json_encode($registro->toArray()));
        }
    }

    public function test_nome_vazio_ou_longo_e_recusado(): void
    {
        foreach (['', '   ', str_repeat('a', 81)] as $nome) {
            try {
                $this->criar($nome);
                $this->fail('deveria recusar o nome');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('nome', $e->errors());
            }
        }
        $this->assertSame(0, ChaveDeApi::query()->count());
    }

    public function test_papel_sem_permissao_e_recusado(): void
    {
        foreach ([Papel::Fiscal, Papel::Vendedor, Papel::Leitura] as $papel) {
            $autor = Usuario::factory()->for($this->tenant)->create(['papel' => $papel]);
            try {
                $this->criar('x', AmbienteDaChave::Live, $autor);
                $this->fail('deveria recusar o papel '.$papel->value);
            } catch (AcessoNegadoException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame(0, ChaveDeApi::query()->count());
    }

    public function test_plano_sem_o_modulo_api_recusa(): void
    {
        $this->tenant->forceFill(['plano_id' => Plano::factory()->create()->id])->save();
        $this->admin->unsetRelation('tenant');

        $this->expectException(ModuloNaoContratadoException::class);
        $this->criar();
    }

    public function test_a_vigesima_primeira_chave_ativa_e_recusada_e_revogada_nao_conta(): void
    {
        for ($i = 0; $i < 19; $i++) {
            $this->chaveAtiva();
        }
        $revogada = $this->chaveAtiva();
        $revogada->forceFill(['revogada_em' => now()])->save();
        $this->criar('vigésima'); // 19 ativas + esta = 20

        try {
            $this->criar('excedente');
            $this->fail('deveria recusar a 21ª');
        } catch (LimiteDeChavesAtingidoException $e) {
            $this->assertSame('API_CHAVES_LIMITE_ATINGIDO', $e->codigo());
            $this->assertSame(422, $e->status());
        }
        $this->assertSame(21, ChaveDeApi::query()->count());
    }

    public function test_revogar_define_revogada_em_e_e_idempotente(): void
    {
        $chave = $this->chaveAtiva();
        $this->travelTo(now()->startOfSecond());

        $primeira = app(RevogarChaveDeApi::class)->executar($this->admin, $chave->id);
        $instante = $primeira->revogada_em;
        $this->assertNotNull($instante);
        $this->assertTrue($primeira->estaRevogada());

        $this->travel(5)->minutes();
        $segunda = app(RevogarChaveDeApi::class)->executar($this->admin, $chave->id);
        $this->assertSame($instante->toIso8601String(), $segunda->revogada_em?->toIso8601String());
    }

    public function test_revogar_exige_papel_de_gestao(): void
    {
        $chave = $this->chaveAtiva();
        $vendedor = Usuario::factory()->for($this->tenant)->create(['papel' => Papel::Vendedor]);

        $this->expectException(AcessoNegadoException::class);
        app(RevogarChaveDeApi::class)->executar($vendedor, $chave->id);
    }

    public function test_revogar_chave_de_outro_tenant_da_modelnotfound(): void
    {
        $chave = $this->chaveAtiva();
        $outro = Tenant::factory()->for(Plano::factory()->comModulos(Modulo::Api))->create();
        app(TenantContext::class)->set($outro->id);
        $adminDoOutro = Usuario::factory()->for($outro)->create(['papel' => Papel::Admin]);

        $this->expectException(ModelNotFoundException::class);
        app(RevogarChaveDeApi::class)->executar($adminDoOutro, $chave->id);
    }

    public function test_listar_traz_so_as_do_tenant_mais_recentes_primeiro_e_sem_hash(): void
    {
        $antiga = $this->chaveAtiva();
        $antiga->forceFill(['created_at' => now()->subDay()])->save();
        $nova = $this->chaveAtiva();
        $outro = Tenant::factory()->for(Plano::factory()->comModulos(Modulo::Api))->create();
        app(TenantContext::class)->set($outro->id);
        $adminDoOutro = Usuario::factory()->for($outro)->create(['papel' => Papel::Admin]);
        ChaveDeApi::factory()->create(['usuario_id' => $adminDoOutro->id]);

        app(TenantContext::class)->set($this->tenant->id);
        $lista = app(ListarChavesDeApi::class)->executar($this->admin);

        $this->assertSame([$nova->id, $antiga->id], $lista->pluck('id')->all());
        $this->assertTrue($lista->first()?->relationLoaded('usuario'));
        $this->assertArrayNotHasKey('hash', $lista->first()?->toArray() ?? ['hash' => 1]);
    }

    public function test_listar_exige_papel_de_gestao(): void
    {
        $this->expectException(AcessoNegadoException::class);
        app(ListarChavesDeApi::class)->executar(Usuario::factory()->for($this->tenant)->create(['papel' => Papel::Leitura]));
    }
}

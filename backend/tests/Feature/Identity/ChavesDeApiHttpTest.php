<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use App\Modules\Identity\Domain\Enums\AmbienteDaChave;
use App\Modules\Identity\Domain\Enums\Papel;
use App\Modules\Identity\Domain\GeradorDeChaveDeApi;
use App\Modules\Identity\Domain\Models\ChaveDeApi;
use App\Modules\Identity\Domain\Models\Usuario;
use App\Modules\Platform\Domain\Enums\Modulo;
use App\Modules\Platform\Domain\Models\Plano;
use App\Modules\Tenancy\Domain\Models\Tenant;
use App\Modules\Tenancy\Domain\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ChavesDeApiHttpTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Usuario $dono;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::factory()->for(Plano::factory()->comModulos(Modulo::Api))->create();
        $this->dono = Usuario::factory()->for($this->tenant)->create(['papel' => Papel::Proprietario]);
    }

    private function chaveExistente(?Tenant $tenant = null, ?Usuario $autor = null): ChaveDeApi
    {
        app(TenantContext::class)->set(($tenant ?? $this->tenant)->id);
        $gerada = (new GeradorDeChaveDeApi)->gerar(AmbienteDaChave::Live);

        return ChaveDeApi::factory()->create([
            'usuario_id' => ($autor ?? $this->dono)->id, 'hash' => $gerada->hash, 'prefixo' => $gerada->prefixo, 'ultimos4' => $gerada->ultimos4,
        ]);
    }

    public function test_criar_devolve_201_e_o_texto_uma_unica_vez(): void
    {
        $resposta = $this->spaComo($this->dono)->postJson('/api/app/chaves-api', ['nome' => 'ERP', 'ambiente' => 'TEST'])
            ->assertCreated()
            ->assertJsonPath('data.nome', 'ERP')
            ->assertJsonPath('data.ambiente', 'TEST')
            ->assertJsonPath('data.usuario.id', $this->dono->id)
            ->assertJsonStructure(['data' => ['id', 'nome', 'ambiente', 'prefixo', 'ultimos4', 'usuario' => ['id', 'nome'], 'ultimo_uso_em', 'revogada_em', 'criada_em', 'chave']]);
        $texto = (string) $resposta->json('data.chave');
        $this->assertMatchesRegularExpression('/^test_[A-Za-z0-9]{40}$/', $texto);

        $lista = $this->spaComo($this->dono)->getJson('/api/app/chaves-api')->assertOk();
        $this->assertStringNotContainsString($texto, $lista->getContent() ?: '');
        $this->assertStringNotContainsString(hash('sha256', $texto), $lista->getContent() ?: '');
        $lista->assertJsonCount(1, 'data');
        $this->assertArrayNotHasKey('chave', $lista->json('data.0'));
        $this->assertArrayNotHasKey('hash', $lista->json('data.0'));
    }

    public function test_validacao_do_nome_e_do_ambiente(): void
    {
        $this->spaComo($this->dono)->postJson('/api/app/chaves-api', ['nome' => '', 'ambiente' => 'LIVE'])
            ->assertUnprocessable()->assertJsonValidationErrors(['nome']);
        $this->spaComo($this->dono)->postJson('/api/app/chaves-api', ['nome' => str_repeat('a', 81), 'ambiente' => 'LIVE'])
            ->assertUnprocessable()->assertJsonValidationErrors(['nome']);
        $this->spaComo($this->dono)->postJson('/api/app/chaves-api', ['nome' => 'ERP', 'ambiente' => 'PROD'])
            ->assertUnprocessable()->assertJsonValidationErrors(['ambiente']);
    }

    public function test_papel_sem_permissao_recebe_403_em_cada_verbo(): void
    {
        $chave = $this->chaveExistente();
        $vendedor = Usuario::factory()->for($this->tenant)->create(['papel' => Papel::Vendedor]);

        $this->spaComo($vendedor)->getJson('/api/app/chaves-api')->assertForbidden();
        $this->spaComo($vendedor)->postJson('/api/app/chaves-api', ['nome' => 'ERP', 'ambiente' => 'LIVE'])->assertForbidden();
        $this->spaComo($vendedor)->deleteJson('/api/app/chaves-api/'.$chave->id)->assertForbidden();
    }

    public function test_modulo_fora_do_plano_devolve_o_erro_de_modulo(): void
    {
        $this->tenant->forceFill(['plano_id' => Plano::factory()->create()->id])->save();

        $this->spaComo($this->dono->fresh() ?? $this->dono)->postJson('/api/app/chaves-api', ['nome' => 'ERP', 'ambiente' => 'LIVE'])
            ->assertForbidden()->assertJsonPath('codigo', 'MODULO_NAO_CONTRATADO');
    }

    public function test_revogar_devolve_a_chave_e_a_listagem_mostra_revogada_em(): void
    {
        $chave = $this->chaveExistente();

        $this->spaComo($this->dono)->deleteJson('/api/app/chaves-api/'.$chave->id)
            ->assertOk()->assertJsonPath('data.id', $chave->id)->assertJsonPath('data.revogada_em', fn ($v) => $v !== null);

        $this->spaComo($this->dono)->getJson('/api/app/chaves-api')
            ->assertOk()->assertJsonPath('data.0.revogada_em', fn ($v) => $v !== null);
    }

    public function test_isolamento_admin_de_outro_tenant_lista_zero_e_recebe_404_ao_revogar(): void
    {
        $chave = $this->chaveExistente();
        $outro = Tenant::factory()->for(Plano::factory()->comModulos(Modulo::Api))->create();
        $adminDoOutro = Usuario::factory()->for($outro)->create(['papel' => Papel::Admin]);

        $this->spaComo($adminDoOutro)->getJson('/api/app/chaves-api')->assertOk()->assertJsonCount(0, 'data');
        $this->spaComo($adminDoOutro)->deleteJson('/api/app/chaves-api/'.$chave->id)->assertNotFound();
        $this->assertNull($chave->fresh()?->revogada_em);
    }
}

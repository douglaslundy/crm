<?php

declare(strict_types=1);

namespace Tests\Feature\Crm;

use App\Modules\Crm\Application\Actions\CancelarProposta;
use App\Modules\Crm\Application\Actions\CriarProposta;
use App\Modules\Crm\Application\Actions\EnviarProposta;
use App\Modules\Crm\Domain\Enums\StatusDoNegocio;
use App\Modules\Crm\Domain\Models\Negocio;
use App\Modules\Identity\Domain\Models\Usuario;
use App\Modules\Platform\Domain\Enums\Modulo;
use App\Modules\Platform\Domain\Models\Plano;
use App\Modules\Tenancy\Domain\Models\Tenant;
use App\Modules\Tenancy\Domain\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\ComCrm;
use Tests\Support\ComPropostas;
use Tests\TestCase;

class PropostaPublicaApiTest extends TestCase
{
    use ComCrm;
    use ComPropostas;
    use RefreshDatabase;

    private Negocio $negocio;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararCrm();
        $this->negocio = $this->negocioComItens();
        $this->token = (string) $this->propostaEnviada($this->negocio)->token;
        app(TenantContext::class)->clear();
    }

    private function url(string $token, string $sufixo = ''): string
    {
        return "/api/public/propostas/{$token}{$sufixo}";
    }

    public function test_get_devolve_so_campos_publicos_sem_sessao(): void
    {
        $r = $this->getJson($this->url($this->token))->assertOk()
            ->assertJsonPath('data.status', 'ENVIADA')
            ->assertJsonPath('data.numero', 1)
            ->assertJsonPath('data.total_centavos', 3000)
            ->assertJsonCount(1, 'data.itens');

        foreach (['id', 'criado_por', 'token', 'token_hash', 'tenant_id', 'negocio_id', 'cliente_id'] as $chave) {
            $this->assertArrayNotHasKey($chave, $r->json('data'));
        }
    }

    public function test_aceitar_devolve_200_ganha_negocio_e_e_idempotente(): void
    {
        $this->postJson($this->url($this->token, '/aceitar'), ['nome' => 'Maria Souza'])
            ->assertOk()->assertJsonPath('data.status', 'ACEITA')->assertJsonPath('data.aceite_nome', 'Maria Souza');
        $this->postJson($this->url($this->token, '/aceitar'), ['nome' => 'Maria Souza'])
            ->assertOk()->assertJsonPath('data.status', 'ACEITA');

        app(TenantContext::class)->set($this->tenant->id);
        $this->assertSame(StatusDoNegocio::Ganho, $this->negocio->refresh()->status);
    }

    public function test_aceite_sem_nome_e_422(): void
    {
        $this->postJson($this->url($this->token, '/aceitar'), ['nome' => ' '])->assertStatus(422);
        $this->postJson($this->url($this->token, '/aceitar'), [])->assertStatus(422);
    }

    public function test_recusar_devolve_200(): void
    {
        $this->postJson($this->url($this->token, '/recusar'), ['motivo' => 'Preço'])
            ->assertOk()->assertJsonPath('data.status', 'RECUSADA');
    }

    public function test_expirada_mostra_status_e_bloqueia_aceite(): void
    {
        $this->travel(16)->days();

        $this->getJson($this->url($this->token))->assertOk()->assertJsonPath('data.status', 'EXPIRADA');
        $this->postJson($this->url($this->token, '/aceitar'), ['nome' => 'Maria'])
            ->assertStatus(422)->assertJsonPath('codigo', 'PROPOSTA_EXPIRADA');
    }

    public function test_token_inexistente_formato_invalido_e_cancelada_dao_o_mesmo_404(): void
    {
        app(TenantContext::class)->set($this->tenant->id);
        $cancelada = $this->propostaEnviada();
        app(CancelarProposta::class)->executar($this->admin, $cancelada);
        $tokenCancelado = (string) $cancelada->token;
        app(TenantContext::class)->clear();

        $corpos = [];
        foreach ([Str::random(64), 'curto', $tokenCancelado] as $token) {
            $r = $this->getJson($this->url($token))->assertNotFound();
            $corpos[] = $r->getContent();
        }
        $this->assertCount(1, array_unique($corpos));
    }

    public function test_token_de_um_tenant_nao_le_proposta_de_outro(): void
    {
        $outro = Tenant::factory()->for(Plano::factory()->comModulos(Modulo::Crm))->create();
        $dono = Usuario::factory()->for($outro)->create();
        app(TenantContext::class)->set($outro->id);
        $negocioB = Negocio::factory()->create(['responsavel_id' => $dono->id]);
        $negocioB->itens()->create(['tipo' => 'PRODUTO', 'item_id' => (string) Str::uuid(), 'descricao' => 'Produto do B', 'quantidade' => 1, 'valor_unitario_centavos' => 777]);
        $b = app(EnviarProposta::class)->executar($dono, app(CriarProposta::class)->executar($dono, $negocioB));
        app(TenantContext::class)->clear();

        $doB = $this->getJson($this->url((string) $b->token))->assertOk()->json('data');
        $doA = $this->getJson($this->url($this->token))->assertOk()->json('data');

        $this->assertSame(777, $doB['total_centavos']);
        $this->assertSame(3000, $doA['total_centavos']);
        $this->assertStringNotContainsString('Produto do B', (string) json_encode($doA));
    }

    public function test_pdf_publico_devolve_pdf(): void
    {
        $r = $this->get($this->url($this->token, '/pdf'));

        $r->assertOk();
        $this->assertStringStartsWith('%PDF', (string) $r->getContent());
    }

    public function test_throttle_corta_depois_de_60_por_minuto(): void
    {
        for ($i = 0; $i < 60; $i++) {
            $this->getJson($this->url($this->token))->assertOk();
        }

        $this->getJson($this->url($this->token))->assertStatus(429);
    }
}

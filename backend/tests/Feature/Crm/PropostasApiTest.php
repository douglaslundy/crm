<?php

declare(strict_types=1);

namespace Tests\Feature\Crm;

use App\Modules\Crm\Application\Actions\AceitarProposta;
use App\Modules\Crm\Domain\Models\ConfiguracaoDoCrm;
use App\Modules\Crm\Domain\Models\Etapa;
use App\Modules\Crm\Domain\Models\Funil;
use App\Modules\Crm\Domain\Models\Negocio;
use App\Modules\Identity\Domain\Enums\Papel;
use App\Modules\Identity\Domain\Models\Usuario;
use App\Modules\Platform\Domain\Enums\Modulo;
use App\Modules\Platform\Domain\Models\Plano;
use App\Modules\Tenancy\Domain\Models\Tenant;
use App\Modules\Tenancy\Domain\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ComCrm;
use Tests\Support\ComPropostas;
use Tests\TestCase;

class PropostasApiTest extends TestCase
{
    use ComCrm;
    use ComPropostas;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararCrm();
    }

    public function test_ciclo_criar_editar_enviar_devolve_link(): void
    {
        $negocio = $this->negocioComItens();
        $api = $this->spaComo($this->admin);

        $id = $api->postJson("/api/app/crm/negocios/{$negocio->id}/propostas", ['validade_dias' => 10, 'desconto_centavos' => 500])
            ->assertCreated()
            ->assertJsonPath('data.status', 'RASCUNHO')
            ->assertJsonPath('data.numero', 1)
            ->assertJsonPath('data.total_centavos', 2500)
            ->assertJsonPath('data.link', null)
            ->json('data.id');

        $api->putJson("/api/app/crm/propostas/{$id}", ['observacoes' => 'Entrega em 5 dias'])
            ->assertOk()->assertJsonPath('data.observacoes', 'Entrega em 5 dias');

        $link = $api->postJson("/api/app/crm/propostas/{$id}/enviar")
            ->assertOk()->assertJsonPath('data.status', 'ENVIADA')->json('data.link');
        $this->assertStringContainsString('/p/', (string) $link);

        $api->getJson("/api/app/crm/negocios/{$negocio->id}/propostas")
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.link', $link);

        $api->postJson("/api/app/crm/propostas/{$id}/cancelar")->assertOk()->assertJsonPath('data.status', 'CANCELADA');
    }

    public function test_pdf_devolve_content_type_pdf(): void
    {
        $proposta = $this->propostaEnviada();

        $r = $this->spaComo($this->admin)->get("/api/app/crm/propostas/{$proposta->id}/pdf");

        $r->assertOk();
        $this->assertStringContainsString('application/pdf', (string) $r->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF', (string) $r->getContent());
    }

    public function test_erro_de_regra_traz_codigo(): void
    {
        $funil = Funil::factory()->padrao()->create();
        $etapa = Etapa::factory()->create(['funil_id' => $funil->id, 'posicao' => 0]);
        $vazio = Negocio::factory()->create(['funil_id' => $funil->id, 'etapa_id' => $etapa->id, 'responsavel_id' => $this->admin->id]);

        $this->spaComo($this->admin)->postJson("/api/app/crm/negocios/{$vazio->id}/propostas")
            ->assertStatus(422)->assertJsonPath('codigo', 'NEGOCIO_SEM_ITENS');
    }

    public function test_dados_de_outro_tenant_sao_404_em_todas_as_rotas(): void
    {
        $proposta = $this->propostaEnviada();
        $outro = Tenant::factory()->for(Plano::factory()->comModulos(Modulo::Crm))->create();
        $intruso = Usuario::factory()->for($outro)->create(['papel' => Papel::Admin]);
        app(TenantContext::class)->set($outro->id);
        $api = $this->spaComo($intruso);

        $api->getJson("/api/app/crm/negocios/{$proposta->negocio_id}/propostas")->assertNotFound();
        $api->postJson("/api/app/crm/negocios/{$proposta->negocio_id}/propostas")->assertNotFound();
        $api->getJson("/api/app/crm/propostas/{$proposta->id}")->assertNotFound();
        $api->putJson("/api/app/crm/propostas/{$proposta->id}", ['observacoes' => 'x'])->assertNotFound();
        $api->postJson("/api/app/crm/propostas/{$proposta->id}/enviar")->assertNotFound();
        $api->postJson("/api/app/crm/propostas/{$proposta->id}/cancelar")->assertNotFound();
        $api->postJson("/api/app/crm/propostas/{$proposta->id}/nota")->assertNotFound();
        $api->get("/api/app/crm/propostas/{$proposta->id}/pdf")->assertNotFound();
    }

    public function test_vendedor_so_ve_propostas_dos_negocios_que_enxerga(): void
    {
        ConfiguracaoDoCrm::create(['vendedor_ve_so_os_seus' => true]);
        $vendedor = $this->usuarioDoPapel(Papel::Vendedor);
        $doColega = $this->propostaEnviada();   // negócio do admin

        $api = $this->spaComo($vendedor);
        $api->getJson("/api/app/crm/propostas/{$doColega->id}")->assertNotFound();
        $api->getJson("/api/app/crm/negocios/{$doColega->negocio_id}/propostas")->assertNotFound();
    }

    public function test_leitura_lista_mas_nao_escreve(): void
    {
        $proposta = $this->propostaEnviada();
        $leitor = $this->usuarioDoPapel(Papel::Leitura);
        $api = $this->spaComo($leitor);

        $api->getJson("/api/app/crm/negocios/{$proposta->negocio_id}/propostas")->assertOk();
        $api->postJson("/api/app/crm/negocios/{$proposta->negocio_id}/propostas")->assertForbidden();
        $api->postJson("/api/app/crm/propostas/{$proposta->id}/cancelar")->assertForbidden();
    }

    public function test_nota_sem_proposta_aceita_e_422_e_sem_fiscal_no_plano_tambem(): void
    {
        $proposta = $this->propostaEnviada();
        $api = $this->spaComo($this->admin);

        $api->postJson("/api/app/crm/propostas/{$proposta->id}/nota")
            ->assertStatus(422)->assertJsonPath('codigo', 'PROPOSTA_NAO_ACEITA');

        app(TenantContext::class)->clear();
        app(AceitarProposta::class)->executar((string) $proposta->token, 'Maria', null);
        app(TenantContext::class)->set($this->tenant->id);

        $api->postJson("/api/app/crm/propostas/{$proposta->id}/nota")
            ->assertStatus(422)->assertJsonPath('codigo', 'FISCAL_INDISPONIVEL');
    }

    public function test_aceita_mostra_aceite_na_resposta(): void
    {
        $proposta = $this->propostaEnviada();
        app(TenantContext::class)->clear();
        app(AceitarProposta::class)->executar((string) $proposta->token, 'Maria', null);
        app(TenantContext::class)->set($this->tenant->id);

        $this->spaComo($this->admin)->getJson("/api/app/crm/propostas/{$proposta->id}")
            ->assertOk()->assertJsonPath('data.status', 'ACEITA')->assertJsonPath('data.aceite_nome', 'Maria');
    }
}

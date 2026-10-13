<?php

declare(strict_types=1);

namespace Tests\Feature\Crm;

use App\Modules\Crm\Application\Actions\CriarProposta;
use App\Modules\Crm\Application\ApresentadorDePropostas;
use App\Modules\Crm\Application\GeradorDePdfDaProposta;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ComCrm;
use Tests\Support\ComPropostas;
use Tests\TestCase;

class PdfDaPropostaTest extends TestCase
{
    use ComCrm;
    use ComPropostas;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararCrm();
    }

    public function test_pdf_comeca_com_assinatura_pdf(): void
    {
        $proposta = $this->propostaEnviada();

        $this->assertStringStartsWith('%PDF', app(GeradorDePdfDaProposta::class)->gerar($proposta));
    }

    public function test_apresentador_publico_nao_vaza_ids_de_usuario_nem_token(): void
    {
        $proposta = $this->propostaEnviada();

        $publico = app(ApresentadorDePropostas::class)->paraPublico($proposta);

        foreach (['criado_por', 'token', 'token_hash', 'tenant_id', 'negocio_id', 'cliente_id', 'id'] as $chave) {
            $this->assertArrayNotHasKey($chave, $publico);
        }
        $this->assertSame($proposta->totalCentavos(), $publico['total_centavos']);
        $this->assertSame('ENVIADA', $publico['status']);
        $this->assertCount(1, $publico['itens']);
    }

    public function test_link_so_aparece_quando_pedido(): void
    {
        $proposta = $this->propostaEnviada();
        $apresentador = app(ApresentadorDePropostas::class);

        $this->assertStringEndsWith('/p/'.$proposta->token, $apresentador->apresentar($proposta, true)['link']);
        $this->assertArrayNotHasKey('link', $apresentador->apresentar($proposta));
    }

    public function test_rascunho_nao_tem_link_mesmo_pedido(): void
    {
        $rascunho = app(CriarProposta::class)->executar($this->admin, $this->negocioComItens());

        $this->assertNull(app(ApresentadorDePropostas::class)->apresentar($rascunho, true)['link']);
    }
}

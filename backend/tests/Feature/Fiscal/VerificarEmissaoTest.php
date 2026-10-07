<?php

declare(strict_types=1);

namespace Tests\Feature\Fiscal;

use App\Modules\Fiscal\Application\Actions\IniciarEmissao;
use App\Modules\Fiscal\Domain\Enums\StatusNota;
use App\Modules\Fiscal\Domain\Exceptions\EmissaoBloqueadaException;
use App\Modules\Fiscal\Domain\Models\EmitenteSerie;
use App\Modules\Identity\Domain\Enums\Papel;
use App\Modules\Identity\Domain\Models\Usuario;
use App\Modules\Shared\Domain\Exceptions\AcessoNegadoException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\CenarioDeVenda;
use Tests\TestCase;

class VerificarEmissaoTest extends TestCase
{
    use CenarioDeVenda;
    use RefreshDatabase;

    private function proximoNumeroDps(): int
    {
        return (int) EmitenteSerie::query()->where('modelo', 'DPS')->value('proximo_numero');
    }

    public function test_nota_apta_passa_e_nada_muda(): void
    {
        $this->montarCenarioVenda();
        $nota = $this->rascunhoDeNfse();

        app(IniciarEmissao::class)->verificar($nota, $this->admin);

        $nota->refresh();
        $this->assertSame(StatusNota::Rascunho, $nota->status);
        $this->assertNull($nota->numero);
        $this->assertSame(1, $this->proximoNumeroDps());
    }

    public function test_nota_bloqueada_lanca_o_mesmo_bloqueio_da_emissao(): void
    {
        $this->montarCenarioVenda();
        $nota = $this->rascunhoDeNfse();
        $this->servico->forceFill(['c_trib_nac' => null])->save();

        try {
            app(IniciarEmissao::class)->verificar($nota, $this->admin);
            $this->fail('deveria bloquear');
        } catch (EmissaoBloqueadaException $e) {
            $this->assertStringStartsWith('FISCAL_', $e->codigo());
        }
        $this->assertSame(StatusNota::Rascunho, $nota->refresh()->status);
        $this->assertSame(1, $this->proximoNumeroDps());
    }

    public function test_nao_grava_o_snapshot_fiscal_da_nota(): void
    {
        $this->montarCenarioVenda();
        $nota = $this->rascunhoDeNfse();

        app(IniciarEmissao::class)->verificar($nota, $this->admin);

        $this->assertNull($nota->refresh()->destinatario);
    }

    public function test_sem_permissao_de_emitir_nega(): void
    {
        $this->montarCenarioVenda();
        $nota = $this->rascunhoDeNfse();
        $vendedor = Usuario::factory()->for($this->tenant)->create(['papel' => Papel::Vendedor]);

        $this->expectException(AcessoNegadoException::class);

        app(IniciarEmissao::class)->verificar($nota, $vendedor);
    }
}

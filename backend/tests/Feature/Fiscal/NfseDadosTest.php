<?php

declare(strict_types=1);

namespace Tests\Feature\Fiscal;

use App\Modules\Catalog\Domain\Models\Servico;
use App\Modules\Fiscal\Domain\Enums\ModeloDocumento;
use App\Modules\Fiscal\Domain\Models\Nota;
use App\Modules\Platform\Domain\Enums\Modulo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\CenarioDeNota;
use Tests\TestCase;

class NfseDadosTest extends TestCase
{
    use CenarioDeNota;
    use RefreshDatabase;

    private function notaDps(): Nota
    {
        $nota = Nota::factory()->create(['emitente_id' => $this->emitente->id]);
        $nota->forceFill(['modelo' => ModeloDocumento::Dps])->save();

        return $nota->refresh();
    }

    public function test_a_chave_da_nfse_com_50_digitos_e_o_numero_oficial_sao_gravados(): void
    {
        $this->montarCenario(Modulo::FiscalNfse);
        $chave = '3550308211444777000161'.str_repeat('1', 28);
        $nota = $this->notaDps();

        $nota->forceFill(['chave' => $chave, 'numero_nfse' => '123'])->save();

        $nota = $nota->refresh();
        $this->assertSame(50, strlen((string) $nota->chave));
        $this->assertSame($chave, $nota->chave);
        $this->assertSame('123', $nota->numero_nfse);
    }

    public function test_item_de_servico_nao_tem_produto_e_guarda_o_snapshot_fiscal(): void
    {
        $this->montarCenario(Modulo::FiscalNfse);
        $servico = Servico::factory()->completo()->create(['nome' => 'Troca de óleo', 'preco_centavos' => 15000, 'codigo_municipal' => '123', 'nbs' => '115090000']);
        $nota = $this->notaDps();

        $item = $nota->itens()->create([
            'servico_id' => $servico->id, 'produto_id' => null, 'ordem' => 1, 'quantidade_milesimos' => 1000,
            'valor_unitario_centavos' => 15000, 'total_centavos' => 15000, 'descricao' => 'Troca de óleo',
            'c_trib_nac' => '140101', 'codigo_municipal' => '123', 'aliquota_iss' => '5.00', 'nbs' => '115090000',
        ]);

        $item = $item->refresh();
        $this->assertNull($item->produto_id);
        $this->assertSame($servico->id, $item->servico_id);
        $this->assertSame('140101', $item->c_trib_nac);
        $this->assertSame('5.00', $item->aliquota_iss);
    }

    public function test_o_resource_expoe_o_numero_da_nfse_e_os_campos_do_servico(): void
    {
        $this->montarCenario(Modulo::FiscalNfse);
        $servico = Servico::factory()->completo()->create();
        $nota = $this->notaDps();
        $nota->forceFill(['numero_nfse' => '77'])->save();
        $nota->itens()->create([
            'servico_id' => $servico->id, 'produto_id' => null, 'ordem' => 1, 'quantidade_milesimos' => 1000,
            'valor_unitario_centavos' => 1000, 'total_centavos' => 1000, 'c_trib_nac' => '140101', 'aliquota_iss' => '5.00',
        ]);

        $this->spaComo($this->admin)->getJson("/api/app/notas/{$nota->id}")
            ->assertOk()
            ->assertJsonPath('data.modelo', 'DPS')
            ->assertJsonPath('data.numero_nfse', '77')
            ->assertJsonPath('data.itens.0.produto_id', null)
            ->assertJsonPath('data.itens.0.servico_id', $servico->id)
            ->assertJsonPath('data.itens.0.c_trib_nac', '140101')
            ->assertJsonPath('data.itens.0.aliquota_iss', '5.00');
    }

    public function test_nota_de_produtos_continua_igual(): void
    {
        $this->montarCenario(Modulo::FiscalNfe);
        $nota = Nota::factory()->create(['emitente_id' => $this->emitente->id]);

        $this->spaComo($this->admin)->getJson("/api/app/notas/{$nota->id}")
            ->assertOk()->assertJsonPath('data.numero_nfse', null);
    }
}

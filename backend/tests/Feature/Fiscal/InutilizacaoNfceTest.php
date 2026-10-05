<?php

declare(strict_types=1);

namespace Tests\Feature\Fiscal;

use App\Modules\Fiscal\Domain\Contracts\EmissorDeNfe;
use App\Modules\Fiscal\Domain\Enums\ModeloDocumento;
use App\Modules\Fiscal\Domain\Models\EmitenteSerie;
use App\Modules\Fiscal\Domain\Models\Inutilizacao;
use App\Modules\Platform\Domain\Enums\Modulo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\CenarioDeNota;
use Tests\Fixtures\EmissorFake;
use Tests\TestCase;

class InutilizacaoNfceTest extends TestCase
{
    use CenarioDeNota;
    use RefreshDatabase;

    private EmissorFake $emissor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->montarCenario(Modulo::FiscalNfe, Modulo::FiscalNfce);
        $this->emissor = new EmissorFake;
        $this->app->instance(EmissorDeNfe::class, $this->emissor);
        EmitenteSerie::factory()->for($this->emitente)->create(['modelo' => ModeloDocumento::Nfce, 'serie' => '2', 'proximo_numero' => 10]);
    }

    /** @return array<string, mixed> */
    private function corpo(string $modelo, string $serie): array
    {
        return ['modelo' => $modelo, 'serie' => $serie, 'numero_inicial' => 3, 'numero_final' => 5, 'justificativa' => 'Numeração perdida por queda do servidor'];
    }

    public function test_inutiliza_faixa_da_nfce_na_serie_do_modelo(): void
    {
        $this->spaComo($this->admin)->postJson('/api/app/inutilizacoes', $this->corpo('NFCE', '2'))
            ->assertCreated()->assertJsonPath('data.modelo', 'NFCE')->assertJsonPath('data.serie', '2');

        $this->assertSame(ModeloDocumento::Nfce, Inutilizacao::query()->firstOrFail()->modelo);
        $this->assertContains('inutilizar', $this->emissor->chamadas);
        $this->assertSame([ModeloDocumento::Nfce], $this->emissor->modelosInutilizados);
    }

    public function test_serie_da_nfe_nao_vale_para_nfce(): void
    {
        $this->spaComo($this->admin)->postJson('/api/app/inutilizacoes', $this->corpo('NFCE', '1'))
            ->assertStatus(422)->assertJsonPath('codigo', 'FISCAL_SERIE_NAO_CONFIGURADA');
    }

    public function test_sem_modelo_continua_sendo_nfe(): void
    {
        $this->emitente->series()->where('modelo', ModeloDocumento::Nfe)->update(['proximo_numero' => 10]);
        $corpo = $this->corpo('NFE', '1');
        unset($corpo['modelo']);

        $this->spaComo($this->admin)->postJson('/api/app/inutilizacoes', $corpo)->assertCreated()->assertJsonPath('data.modelo', 'NFE');
    }

    public function test_tenant_sem_modulo_nfce_nao_inutiliza_nfce(): void
    {
        $this->tenant->plano->modulos()->where('modulo', Modulo::FiscalNfce)->delete();
        $this->tenant->plano->unsetRelation('modulos');

        $this->spaComo($this->admin)->postJson('/api/app/inutilizacoes', $this->corpo('NFCE', '2'))
            ->assertStatus(403)->assertJsonPath('codigo', 'MODULO_NAO_CONTRATADO');
    }

    public function test_modelo_invalido_da_422(): void
    {
        $this->spaComo($this->admin)->postJson('/api/app/inutilizacoes', $this->corpo('XYZ', '1'))->assertStatus(422)->assertJsonValidationErrors('modelo');
    }
}

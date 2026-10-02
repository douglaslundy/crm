<?php

declare(strict_types=1);

namespace Tests\Feature\Fiscal;

use App\Modules\Fiscal\Domain\Enums\StatusNota;
use App\Modules\Fiscal\Domain\Models\Nota;
use App\Modules\Platform\Application\EntitlementService;
use App\Modules\Platform\Domain\Enums\Recurso;
use App\Modules\Platform\Domain\Exceptions\LimiteDoPlanoAtingidoException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\CenarioDeNota;
use Tests\TestCase;

class ContadorDeDocumentosMesTest extends TestCase
{
    use CenarioDeNota;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->montarCenario();
    }

    private function nota(StatusNota $status, string $ambiente, \DateTimeInterface $emitidoEm): void
    {
        $nota = Nota::factory()->create(['emitente_id' => $this->emitente->id, 'cliente_id' => $this->cliente->id]);
        $nota->forceFill(['status' => $status, 'ambiente' => $ambiente, 'emitido_em' => $emitidoEm])->save();
    }

    public function test_conta_so_autorizadas_em_producao_no_mes_corrente(): void
    {
        $this->nota(StatusNota::Autorizada, 'PRODUCAO', now());
        $this->nota(StatusNota::Autorizada, 'HOMOLOGACAO', now());
        $this->nota(StatusNota::Rejeitada, 'PRODUCAO', now());
        $this->nota(StatusNota::Autorizada, 'PRODUCAO', now()->subMonth());

        $this->assertSame(1, app(EntitlementService::class)->uso($this->tenant, Recurso::DocumentosMes));
    }

    public function test_limite_do_plano_bloqueia_com_codigo_estavel(): void
    {
        $this->tenant->plano->limites()->where('recurso', Recurso::DocumentosMes->value)->update(['limite' => 1]);
        $this->tenant->unsetRelation('plano');
        $this->nota(StatusNota::Autorizada, 'PRODUCAO', now());

        $this->expectException(LimiteDoPlanoAtingidoException::class);
        app(EntitlementService::class)->garantirCapacidade($this->tenant->refresh(), Recurso::DocumentosMes);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature\Fiscal;

use App\Modules\Fiscal\Application\ContadorDeDocumentosMes;
use App\Modules\Fiscal\Domain\Enums\StatusNota;
use App\Modules\Fiscal\Domain\Models\Nota;
use App\Modules\Platform\Application\EntitlementService;
use App\Modules\Platform\Domain\Contracts\ContadorDeUsoMensal;
use App\Modules\Platform\Domain\Enums\Recurso;
use App\Modules\Platform\Domain\Exceptions\LimiteDoPlanoAtingidoException;
use Carbon\CarbonImmutable;
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

    public function test_conta_autorizadas_e_canceladas_em_producao_no_mes_corrente(): void
    {
        $this->nota(StatusNota::Autorizada, 'PRODUCAO', now());
        $this->nota(StatusNota::Cancelada, 'PRODUCAO', now());
        $this->nota(StatusNota::Cancelando, 'PRODUCAO', now());
        $this->nota(StatusNota::Autorizada, 'HOMOLOGACAO', now());
        $this->nota(StatusNota::Cancelada, 'HOMOLOGACAO', now());
        $this->nota(StatusNota::Rejeitada, 'PRODUCAO', now());
        $this->nota(StatusNota::Autorizada, 'PRODUCAO', now()->subMonth());

        $this->assertSame(3, app(EntitlementService::class)->uso($this->tenant, Recurso::DocumentosMes), 'cancelada continua contando');
    }

    public function test_conta_no_mes_pedido_com_os_limites_do_primeiro_e_do_ultimo_instante(): void
    {
        $this->nota(StatusNota::Autorizada, 'PRODUCAO', CarbonImmutable::parse('2026-09-30 23:59:59'));
        $this->nota(StatusNota::Autorizada, 'PRODUCAO', CarbonImmutable::parse('2026-10-01 00:00:00'));
        $this->nota(StatusNota::Cancelada, 'PRODUCAO', CarbonImmutable::parse('2026-10-31 23:59:59'));
        $this->nota(StatusNota::Autorizada, 'PRODUCAO', CarbonImmutable::parse('2026-11-01 00:00:00'));
        $this->nota(StatusNota::Autorizada, 'HOMOLOGACAO', CarbonImmutable::parse('2026-10-15'));
        $this->nota(StatusNota::Rejeitada, 'PRODUCAO', CarbonImmutable::parse('2026-10-15'));

        $contador = app(ContadorDeDocumentosMes::class);

        $this->assertInstanceOf(ContadorDeUsoMensal::class, $contador);
        $this->assertSame(2, $contador->contarNoMes($this->tenant, CarbonImmutable::parse('2026-10-20')));
        $this->assertSame(1, $contador->contarNoMes($this->tenant, CarbonImmutable::parse('2026-09-05')));
        $this->assertSame(1, $contador->contarNoMes($this->tenant, CarbonImmutable::parse('2026-11-30')));
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

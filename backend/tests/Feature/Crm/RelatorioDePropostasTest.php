<?php

declare(strict_types=1);

namespace Tests\Feature\Crm;

use App\Modules\Crm\Application\Relatorio\PeriodoDoRelatorio;
use App\Modules\Crm\Application\Relatorio\RelatorioDePropostas;
use App\Modules\Crm\Domain\Contracts\ConsultorDeValorFaturado;
use App\Modules\Crm\Domain\Enums\StatusDaProposta;
use App\Modules\Crm\Domain\Models\Proposta;
use App\Modules\Fiscal\Domain\Enums\StatusNota;
use App\Modules\Fiscal\Domain\Models\Emitente;
use App\Modules\Fiscal\Domain\Models\Nota;
use App\Modules\Identity\Domain\Enums\Papel;
use App\Modules\Identity\Domain\Models\Usuario;
use App\Modules\Platform\Domain\Enums\Modulo;
use App\Modules\Platform\Domain\Enums\Recurso;
use App\Modules\Platform\Domain\Models\Plano;
use App\Modules\Tenancy\Domain\Models\Tenant;
use App\Modules\Tenancy\Domain\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ComCrm;
use Tests\Support\ComPropostas;
use Tests\TestCase;

class RelatorioDePropostasTest extends TestCase
{
    use ComCrm;
    use ComPropostas;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $plano = Plano::factory()->comModulos(Modulo::Crm, Modulo::FiscalNfe)
            ->comLimites([Recurso::Clientes->value => Plano::ILIMITADO]);
        $this->tenant = Tenant::factory()->for($plano)->create();
        $this->admin = Usuario::factory()->for($this->tenant)->create(['papel' => Papel::Admin]);
        app(TenantContext::class)->set($this->tenant->id);
    }

    private function periodo(): PeriodoDoRelatorio
    {
        return PeriodoDoRelatorio::entre('2026-10-01', '2026-10-31');
    }

    /** @param array<string, mixed> $extra */
    private function aceita(string $quando, array $extra = []): Proposta
    {
        $proposta = $this->propostaEnviada($this->negocioComItens(2, 1500));
        $proposta->forceFill(['status' => StatusDaProposta::Aceita, 'respondida_em' => $quando, ...$extra])->save();

        return $proposta;
    }

    public function test_conta_enviadas_aceitas_recusadas_e_soma_o_valor_aceito_com_desconto(): void
    {
        $this->aceita('2026-10-10 15:00:00', ['desconto_centavos' => 500]);
        $recusada = $this->propostaEnviada();
        $recusada->forceFill(['status' => StatusDaProposta::Recusada, 'respondida_em' => '2026-10-12 15:00:00'])->save();
        $this->aceita('2026-09-30 15:00:00'); // aceita fora do período

        Proposta::query()->update(['enviada_em' => '2026-10-05 12:00:00']);

        $r = app(RelatorioDePropostas::class)->propostas($this->admin, $this->periodo(), null);

        $this->assertSame(3, $r['enviadas']);
        $this->assertSame(1, $r['aceitas']);
        $this->assertSame(1, $r['recusadas']);
        $this->assertSame(2500, $r['valor_aceito_centavos']); // 2 × 1500 − 500
        $this->assertSame(0.5, $r['taxa_de_aceite']);
    }

    public function test_sem_propostas_respondidas_a_taxa_e_nula(): void
    {
        $r = app(RelatorioDePropostas::class)->propostas($this->admin, $this->periodo(), null);

        $this->assertNull($r['taxa_de_aceite']);
        $this->assertSame(0, $r['valor_aceito_centavos']);
    }

    public function test_vendedor_restrito_so_conta_propostas_dos_proprios_negocios(): void
    {
        $vendedor = $this->usuarioDoPapel(Papel::Vendedor);
        $this->aceita('2026-10-10 15:00:00'); // negócio do admin

        $r = app(RelatorioDePropostas::class)->propostas($vendedor, $this->periodo(), null);

        $this->assertSame(0, $r['aceitas']);
    }

    public function test_faturado_soma_so_notas_autorizadas_das_propostas_aceitas_no_periodo(): void
    {
        $emitente = Emitente::factory()->create();
        $autorizada = Nota::factory()->create(['emitente_id' => $emitente->id, 'status' => StatusNota::Autorizada, 'total_centavos' => 2500]);
        $rascunho = Nota::factory()->create(['emitente_id' => $emitente->id, 'status' => StatusNota::Rascunho, 'total_centavos' => 9000]);
        $this->aceita('2026-10-10 15:00:00', ['notas_ids' => [$autorizada->id, $rascunho->id]]);
        $foraDoPeriodo = Nota::factory()->create(['emitente_id' => $emitente->id, 'status' => StatusNota::Autorizada, 'total_centavos' => 7000]);
        $this->aceita('2026-09-10 15:00:00', ['notas_ids' => [$foraDoPeriodo->id]]);

        $this->assertSame(2500, app(RelatorioDePropostas::class)->faturado($this->admin, $this->periodo(), null));
    }

    public function test_faturado_e_zero_com_fiscal_e_sem_nota_autorizada(): void
    {
        $this->aceita('2026-10-10 15:00:00');

        $this->assertSame(0, app(RelatorioDePropostas::class)->faturado($this->admin, $this->periodo(), null));
    }

    public function test_faturado_e_nulo_sem_fiscal_no_plano(): void
    {
        $plano = Plano::factory()->comModulos(Modulo::Crm);
        $tenant = Tenant::factory()->for($plano)->create();
        $semFiscal = Usuario::factory()->for($tenant)->create(['papel' => Papel::Admin]);
        app(TenantContext::class)->set($tenant->id);

        $this->assertFalse(app(ConsultorDeValorFaturado::class)->disponivel($semFiscal));
        $this->assertNull(app(RelatorioDePropostas::class)->faturado($semFiscal, $this->periodo(), null));
    }
}

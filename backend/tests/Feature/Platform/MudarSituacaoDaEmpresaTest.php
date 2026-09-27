<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Modules\Identity\Domain\Enums\Papel;
use App\Modules\Identity\Domain\Models\Usuario;
use App\Modules\Platform\Application\Actions\MudarSituacaoDaEmpresa;
use App\Modules\Platform\Domain\Exceptions\TransicaoDeSituacaoInvalidaException;
use App\Modules\Tenancy\Domain\Enums\SituacaoAssinatura;
use App\Modules\Tenancy\Domain\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class MudarSituacaoDaEmpresaTest extends TestCase
{
    use RefreshDatabase;

    public function test_transicao_valida_altera_e_audita_com_motivo(): void
    {
        $admin = Usuario::factory()->create(['tenant_id' => null, 'papel' => Papel::Superadmin]);
        $tenant = Tenant::factory()->situacao(SituacaoAssinatura::Pendente)->create();

        app(MudarSituacaoDaEmpresa::class)->executar($tenant, SituacaoAssinatura::Ativa, 'Pagamento conferido.', $admin);

        $tenant->refresh();
        $this->assertSame(SituacaoAssinatura::Ativa, $tenant->situacao);
        $this->assertNotNull($tenant->situacao_alterada_em);

        $registro = Activity::query()->where('event', 'situacao_alterada')->firstOrFail();
        $this->assertSame($tenant->id, $registro->subject_id);
        $this->assertSame($admin->id, $registro->causer_id);
        $this->assertSame('PENDENTE', $registro->getExtraProperty('de'));
        $this->assertSame('ATIVA', $registro->getExtraProperty('para'));
        $this->assertSame('Pagamento conferido.', $registro->getExtraProperty('motivo'));
    }

    public function test_transicao_invalida_lanca_e_nao_altera(): void
    {
        $tenant = Tenant::factory()->situacao(SituacaoAssinatura::Cancelada)->create();

        try {
            app(MudarSituacaoDaEmpresa::class)->executar($tenant, SituacaoAssinatura::Ativa, 'x', null);
            $this->fail('Deveria lançar.');
        } catch (TransicaoDeSituacaoInvalidaException $e) {
            $this->assertSame('TRANSICAO_INVALIDA', $e->codigo());
            $this->assertSame('Não é possível mudar a situação de CANCELADA para ATIVA.', $e->getMessage());
        }

        $this->assertSame(SituacaoAssinatura::Cancelada, $tenant->refresh()->situacao);
        $this->assertSame(0, Activity::query()->count());
    }

    public function test_valida_a_transicao_sobre_a_situacao_recarregada_do_banco(): void
    {
        // $tenant em memória ainda está TESTE; outra requisição já cancelou no banco.
        $tenant = Tenant::factory()->situacao(SituacaoAssinatura::Teste)->create();
        Tenant::query()->whereKey($tenant->id)->update(['situacao' => SituacaoAssinatura::Cancelada->value]);

        try {
            app(MudarSituacaoDaEmpresa::class)->executar($tenant, SituacaoAssinatura::Ativa, 'x', null);
            $this->fail('Deveria lançar.');
        } catch (TransicaoDeSituacaoInvalidaException $e) {
            $this->assertSame('TRANSICAO_INVALIDA', $e->codigo());
            $this->assertSame('Não é possível mudar a situação de CANCELADA para ATIVA.', $e->getMessage());
        }

        $this->assertSame(SituacaoAssinatura::Cancelada, $tenant->fresh()->situacao);
        $this->assertSame(0, Activity::query()->count());
    }
}

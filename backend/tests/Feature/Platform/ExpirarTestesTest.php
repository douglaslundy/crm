<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Modules\Tenancy\Domain\Enums\SituacaoAssinatura;
use App\Modules\Tenancy\Domain\Models\Tenant;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class ExpirarTestesTest extends TestCase
{
    use RefreshDatabase;

    public function test_suspende_teste_vencido_e_mantem_o_que_termina_hoje(): void
    {
        $this->travelTo('2026-10-10 12:00:00'); // UTC = 09:00 em São Paulo
        $vencido = Tenant::factory()->emTeste('2026-10-09')->create();
        $terminaHoje = Tenant::factory()->emTeste('2026-10-10')->create();
        $ativa = Tenant::factory()->create();

        $this->artisan('assinaturas:expirar-testes')->assertSuccessful();

        $this->assertSame(SituacaoAssinatura::Suspensa, $vencido->refresh()->situacao);
        $this->assertSame(SituacaoAssinatura::Teste, $terminaHoje->refresh()->situacao);
        $this->assertSame(SituacaoAssinatura::Ativa, $ativa->refresh()->situacao);
    }

    public function test_usa_o_dia_de_sao_paulo_e_nao_o_utc(): void
    {
        // 01:00 UTC do dia 11 = 22:00 do dia 10 em São Paulo: o teste que termina dia 10 ainda vale.
        $this->travelTo('2026-10-11 01:00:00');
        $tenant = Tenant::factory()->emTeste('2026-10-10')->create();

        $this->artisan('assinaturas:expirar-testes')->assertSuccessful();

        $this->assertSame(SituacaoAssinatura::Teste, $tenant->refresh()->situacao);
    }

    public function test_e_idempotente(): void
    {
        $this->travelTo('2026-10-10 12:00:00');
        Tenant::factory()->emTeste('2026-10-01')->create();

        $this->artisan('assinaturas:expirar-testes')->assertSuccessful();
        $this->artisan('assinaturas:expirar-testes')->assertSuccessful();

        $this->assertSame(1, Activity::query()->where('event', 'situacao_alterada')->count());
    }

    public function test_agendado_diariamente_as_00_10_de_sao_paulo(): void
    {
        $evento = collect(app(Schedule::class)->events())
            ->first(fn ($e): bool => str_contains((string) $e->command, 'assinaturas:expirar-testes'));

        $this->assertNotNull($evento);
        $this->assertSame('10 0 * * *', $evento->expression);
        $this->assertSame('America/Sao_Paulo', $evento->timezone);
    }
}

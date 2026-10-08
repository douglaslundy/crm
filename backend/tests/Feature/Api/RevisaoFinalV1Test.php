<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Modules\Tenancy\Domain\Enums\SituacaoAssinatura;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\CenarioDeApi;
use Tests\TestCase;

class RevisaoFinalV1Test extends TestCase
{
    use CenarioDeApi;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->montarApi();
    }

    public function test_conta_suspensa_ainda_revoga_chave(): void
    {
        $this->tenant->forceFill(['situacao' => SituacaoAssinatura::Suspensa])->save();

        $this->spaComo($this->admin)->deleteJson('/api/app/chaves-api/'.$this->chave->id)->assertOk();
        $this->comoApi()->getJson('/api/v1/clientes')->assertUnauthorized();
    }

    public function test_inadimplente_escreve_como_no_app(): void
    {
        $this->tenant->forceFill(['situacao' => SituacaoAssinatura::Inadimplente])->save();

        $this->comoApi()->postJson('/api/v1/clientes', ['tipo' => 'PF', 'nome' => 'Ana'])->assertCreated();
    }

    public function test_atualizado_desde_impossivel_e_422(): void
    {
        foreach (['2026-13-45', '2026-02-30', '2026-10-08T25:00:00Z'] as $valor) {
            $this->comoApi()->getJson('/api/v1/clientes?atualizado_desde='.urlencode($valor))
                ->assertUnprocessable()->assertJsonPath('codigo', 'VALIDACAO');
        }
    }

    public function test_cursor_com_data_impossivel_e_422(): void
    {
        $cursor = base64_encode((string) json_encode(['u' => '2026-99-99 99:99:99', 'i' => fake()->uuid()]));

        $this->comoApi()->getJson('/api/v1/clientes?cursor='.urlencode($cursor))->assertUnprocessable()->assertJsonPath('codigo', 'CURSOR_INVALIDO');
    }

    public function test_id_que_nao_e_uuid_e_404(): void
    {
        foreach (['clientes', 'produtos', 'servicos'] as $recurso) {
            $this->comoApi()->getJson("/api/v1/{$recurso}/abc")->assertNotFound()->assertJsonPath('codigo', 'RECURSO_NAO_ENCONTRADO');
            $this->comoApi()->putJson("/api/v1/{$recurso}/abc", [])->assertNotFound();
        }
    }
}

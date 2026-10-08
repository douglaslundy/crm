<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Modules\Fiscal\Domain\Contracts\EmissorDeNfe;
use App\Modules\Fiscal\Domain\Enums\StatusNota;
use App\Modules\Fiscal\Domain\Models\Emitente;
use App\Modules\Fiscal\Domain\Models\Inutilizacao;
use App\Modules\Fiscal\Domain\Models\Nota;
use App\Modules\Fiscal\Domain\Models\NotaEvento;
use App\Modules\Identity\Domain\Enums\AmbienteDaChave;
use App\Modules\Platform\Domain\Enums\Modulo;
use App\Modules\Platform\Domain\Enums\Recurso;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\CenarioDeApi;
use Tests\Fixtures\CenarioDeNota;
use Tests\Fixtures\EmissorFake;
use Tests\TestCase;

class CancelarEInutilizarV1Test extends TestCase
{
    use CenarioDeApi;
    use CenarioDeNota;
    use RefreshDatabase;

    private const JUSTIFICATIVA = 'Cancelamento por erro de digitação do valor total';

    protected function setUp(): void
    {
        parent::setUp();
        $this->montarCenario(Modulo::Api, Modulo::FiscalNfe);
        $this->tenant->plano?->limites()->create(['recurso' => Recurso::ApiRequisicoesMin->value, 'limite' => -1]);
        [$this->chave, $this->textoDaChave] = $this->novaChave($this->admin, AmbienteDaChave::Test);
        $this->app->instance(EmissorDeNfe::class, new EmissorFake);
        $this->emitente->series()->update(['proximo_numero' => 10]);
    }

    private function autorizada(?Emitente $emitente = null, ?StatusNota $status = null): Nota
    {
        $nota = Nota::factory()->create(['emitente_id' => ($emitente ?? $this->emitente)->id, 'cliente_id' => $this->cliente->id]);
        $nota->forceFill([
            'status' => $status ?? StatusNota::Autorizada, 'ambiente' => 'HOMOLOGACAO', 'serie' => '1', 'numero' => $numero = (int) Nota::query()->max('numero') + 1,
            'chave' => str_repeat((string) ($numero % 10), 44), 'protocolo' => '135260000000001', 'xml_autorizado' => '<nfeProc>x</nfeProc>', 'emitido_em' => now(),
        ])->save();

        return $nota->refresh();
    }

    /** @return array<string, mixed> */
    private function corpoInutilizacao(array $extra = []): array
    {
        return array_replace(['serie' => '1', 'numero_inicial' => 3, 'numero_final' => 5, 'justificativa' => 'Numeração perdida por queda do servidor'], $extra);
    }

    public function test_cancelar_devolve_202_e_cancela_pelo_mesmo_fluxo_do_app(): void
    {
        $nota = $this->autorizada();

        $this->comoApi()->postJson("/api/v1/notas/{$nota->id}/cancelar", ['justificativa' => self::JUSTIFICATIVA])
            ->assertStatus(202)->assertJsonStructure(['data' => ['id', 'status']]);

        $this->comoApi()->getJson("/api/v1/notas/{$nota->id}")->assertOk()
            ->assertJsonPath('data.status', 'CANCELADA')->assertJsonPath('data.cancelamento_justificativa', self::JUSTIFICATIVA);
        $this->assertSame($this->admin->id, NotaEvento::query()->where('nota_id', $nota->id)->where('status_para', 'CANCELANDO')->firstOrFail()->usuario_id);
    }

    public function test_justificativa_curta_ou_longa_da_422(): void
    {
        $nota = $this->autorizada();

        foreach ([str_repeat('a', 14), str_repeat('a', 256), ''] as $justificativa) {
            $this->comoApi()->postJson("/api/v1/notas/{$nota->id}/cancelar", ['justificativa' => $justificativa])
                ->assertUnprocessable()->assertJsonPath('codigo', 'VALIDACAO');
        }
    }

    public function test_nota_nao_autorizada_e_rascunho(): void
    {
        $rejeitada = $this->autorizada(null, StatusNota::Rejeitada);
        $rascunho = $this->autorizada(null, StatusNota::Rascunho);

        $resposta = $this->comoApi()->postJson("/api/v1/notas/{$rejeitada->id}/cancelar", ['justificativa' => self::JUSTIFICATIVA]);
        $this->assertGreaterThanOrEqual(400, $resposta->status());
        $this->assertStringStartsWith('application/problem+json', (string) $resposta->headers->get('Content-Type'));
        $this->comoApi()->postJson("/api/v1/notas/{$rascunho->id}/cancelar", ['justificativa' => self::JUSTIFICATIVA])->assertNotFound();
    }

    public function test_idempotency_key_no_cancelar_devolve_a_mesma_resposta_sem_repetir(): void
    {
        $nota = $this->autorizada();
        $chamar = fn () => $this->comoApi()->withHeaders(['Idempotency-Key' => 'cancela-1'])
            ->postJson("/api/v1/notas/{$nota->id}/cancelar", ['justificativa' => self::JUSTIFICATIVA]);

        $primeira = $chamar()->assertStatus(202);
        $eventos = NotaEvento::query()->where('nota_id', $nota->id)->count();
        $segunda = $chamar()->assertStatus(202);

        $this->assertSame('true', $segunda->headers->get('Idempotent-Replayed'));
        $this->assertSame($primeira->json(), $segunda->json());
        $this->assertSame($eventos, NotaEvento::query()->where('nota_id', $nota->id)->count());
    }

    public function test_cancelar_nota_de_outro_tenant_e_404(): void
    {
        $alheia = $this->emOutroTenant(fn () => $this->autorizada(Emitente::factory()->completo()->create()));

        $resposta = $this->comoApi()->postJson("/api/v1/notas/{$alheia->id}/cancelar", ['justificativa' => self::JUSTIFICATIVA])->assertNotFound();
        $this->assertStringStartsWith('application/problem+json', (string) $resposta->headers->get('Content-Type'));
        $this->assertSame(StatusNota::Autorizada, $alheia->fresh()?->status);
    }

    public function test_inutilizar_faixa_valida_devolve_201_e_lista_por_cursor(): void
    {
        $this->comoApi()->postJson('/api/v1/notas/inutilizacoes', $this->corpoInutilizacao())->assertCreated()
            ->assertJsonPath('data.status', 'INUTILIZADA')->assertJsonPath('data.numero_inicial', 3)->assertJsonMissingPath('data.xml_resposta');

        $this->comoApi()->getJson('/api/v1/notas/inutilizacoes')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.numero_final', 5)->assertJsonPath('links.proxima', null);
    }

    public function test_inutilizar_valida_o_corpo_e_aplica_os_bloqueios_do_app(): void
    {
        $this->comoApi()->postJson('/api/v1/notas/inutilizacoes', $this->corpoInutilizacao(['justificativa' => 'curta']))->assertUnprocessable()->assertJsonPath('codigo', 'VALIDACAO');
        $this->comoApi()->postJson('/api/v1/notas/inutilizacoes', $this->corpoInutilizacao(['numero_inicial' => 'a']))->assertUnprocessable();
        $this->comoApi()->postJson('/api/v1/notas/inutilizacoes', $this->corpoInutilizacao(['numero_inicial' => 8, 'numero_final' => 12]))
            ->assertStatus(422)->assertJsonPath('codigo', 'FISCAL_FAIXA_NAO_ALOCADA');
    }

    public function test_inutilizar_com_idempotency_key_nao_repete(): void
    {
        $chamar = fn () => $this->comoApi()->withHeaders(['Idempotency-Key' => 'inut-1'])->postJson('/api/v1/notas/inutilizacoes', $this->corpoInutilizacao());

        $chamar()->assertCreated();
        $this->assertSame('true', $chamar()->assertCreated()->headers->get('Idempotent-Replayed'));
        $this->assertSame(1, Inutilizacao::query()->count());
    }

    public function test_lista_de_inutilizacoes_e_isolada_por_tenant_e_a_rota_nao_e_capturada_por_notas_id(): void
    {
        $this->emOutroTenant(fn () => Inutilizacao::factory()->create());

        $this->comoApi()->getJson('/api/v1/notas/inutilizacoes')->assertOk()->assertJsonCount(0, 'data');
    }
}

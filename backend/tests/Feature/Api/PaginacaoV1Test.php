<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Modules\Customers\Domain\Models\Cliente;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\CenarioDeApi;
use Tests\TestCase;

class PaginacaoV1Test extends TestCase
{
    use CenarioDeApi;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->montarApi();
    }

    /** @return list<string> */
    private function semear(int $quantidade): array
    {
        $ids = [];
        for ($i = 1; $i <= $quantidade; $i++) {
            // Os dois primeiros e os dois seguintes repetem o updated_at: o desempate é pelo id.
            $momento = now()->startOfSecond()->subMinutes(intdiv($i + 1, 2));
            $ids[] = Cliente::factory()->for($this->tenant)->create(['nome' => "Cliente {$i}", 'updated_at' => $momento])->id;
        }

        return $ids;
    }

    public function test_tres_paginas_cobrem_todos_os_registros_sem_repetir(): void
    {
        $todos = $this->semear(5);

        $vistos = [];
        $url = '/api/v1/clientes?limite=2';
        for ($pagina = 1; $pagina <= 3; $pagina++) {
            $resposta = $this->comoApi()->getJson($url)->assertOk()->assertJsonPath('meta.limite', 2);
            array_push($vistos, ...array_column($resposta->json('data'), 'id'));
            $proxima = $resposta->json('links.proxima');
            if ($pagina < 3) {
                $this->assertNotNull($proxima);
                $this->assertCount(2, $resposta->json('data'));
                $url = $proxima;
            } else {
                $this->assertNull($proxima);
                $this->assertCount(1, $resposta->json('data'));
            }
        }

        $this->assertCount(5, array_unique($vistos));
        $this->assertEqualsCanonicalizing($todos, $vistos);
    }

    public function test_a_proxima_pagina_mantem_os_filtros_e_o_limite(): void
    {
        $this->semear(5);

        $proxima = (string) $this->comoApi()->getJson('/api/v1/clientes?limite=2&q=Cliente')->assertOk()->json('links.proxima');

        $this->assertStringContainsString('limite=2', $proxima);
        $this->assertStringContainsString('q=Cliente', $proxima);
        $this->assertStringContainsString('cursor=', $proxima);
    }

    public function test_limite_fora_da_faixa_e_422(): void
    {
        foreach (['101', '0', '-1', 'abc'] as $limite) {
            $resposta = $this->comoApi()->getJson('/api/v1/clientes?limite='.$limite)->assertUnprocessable()->assertJsonPath('codigo', 'VALIDACAO');
            $this->assertContains('limite', array_column($resposta->json('errors'), 'campo'));
        }
        $this->comoApi()->getJson('/api/v1/clientes?limite=100')->assertOk();
    }

    public function test_padrao_e_cinquenta_por_pagina(): void
    {
        $this->semear(1);

        $this->comoApi()->getJson('/api/v1/clientes')->assertOk()->assertJsonPath('meta.limite', 50)->assertJsonPath('links.proxima', null);
    }

    public function test_cursor_adulterado_e_422(): void
    {
        $this->semear(3);

        foreach (['xxx', base64_encode('{"u":1}'), base64_encode('não é json'), base64_encode(json_encode(['u' => 'ontem', 'i' => 'x']))] as $cursor) {
            $this->comoApi()->getJson('/api/v1/clientes?cursor='.urlencode($cursor))->assertUnprocessable()->assertJsonPath('codigo', 'CURSOR_INVALIDO');
        }
    }

    public function test_q_procura_por_nome_e_escapa_curingas(): void
    {
        Cliente::factory()->for($this->tenant)->create(['nome' => 'Maria 100% Lojas']);
        Cliente::factory()->for($this->tenant)->create(['nome' => 'Maria 1000 Lojas']);
        Cliente::factory()->for($this->tenant)->create(['nome' => 'João']);

        $this->assertCount(2, $this->comoApi()->getJson('/api/v1/clientes?q=maria')->json('data'));
        $curinga = $this->comoApi()->getJson('/api/v1/clientes?q='.urlencode('100%'))->assertOk();
        $this->assertSame(['Maria 100% Lojas'], array_column($curinga->json('data'), 'nome'));
        $this->assertCount(0, $this->comoApi()->getJson('/api/v1/clientes?q='.urlencode('M_ria'))->json('data'));
    }

    public function test_atualizado_desde_filtra_e_valida(): void
    {
        $agora = now()->startOfSecond();
        Cliente::factory()->for($this->tenant)->create(['nome' => 'Velho', 'updated_at' => $agora->copy()->subDays(5)]);
        Cliente::factory()->for($this->tenant)->create(['nome' => 'Novo', 'updated_at' => $agora->copy()->subHour()]);

        $resposta = $this->comoApi()->getJson('/api/v1/clientes?atualizado_desde='.urlencode($agora->copy()->subDay()->toIso8601String()))->assertOk();
        $this->assertSame(['Novo'], array_column($resposta->json('data'), 'nome'));

        $this->comoApi()->getJson('/api/v1/clientes?atualizado_desde=ontem')->assertUnprocessable()->assertJsonPath('codigo', 'VALIDACAO');
    }
}

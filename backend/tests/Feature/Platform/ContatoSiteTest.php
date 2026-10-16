<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Modules\Platform\Domain\Models\ContatoSite;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Tests\TestCase;

class ContatoSiteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    /** @return array{pergunta: string, token: string} */
    private function desafio(): array
    {
        return $this->getJson('/api/publico/contato/desafio')->assertOk()->json('data');
    }

    private function resposta(string $pergunta): string
    {
        [$a, $b] = array_map('intval', explode(' + ', $pergunta));

        return (string) ($a + $b);
    }

    /** @param array<string, mixed> $extra @return array<string, mixed> */
    private function dados(array $extra = []): array
    {
        $d = $this->desafio();

        return array_merge([
            'nome' => 'Ana Souza',
            'email' => 'ana@exemplo.com',
            'mensagem' => 'Quero conhecer o sistema.',
            'desafio_token' => $d['token'],
            'desafio_resposta' => $this->resposta($d['pergunta']),
        ], $extra);
    }

    public function test_desafio_devolve_soma_e_token_sem_expor_a_resposta(): void
    {
        $d = $this->desafio();

        $this->assertMatchesRegularExpression('/^[1-9] \+ [1-9]$/', $d['pergunta']);
        $this->assertStringNotContainsString($this->resposta($d['pergunta']).'|', $d['token']);
    }

    public function test_envia_com_resposta_certa_e_grava(): void
    {
        $this->postJson('/api/publico/contato', $this->dados())->assertCreated();

        $this->assertDatabaseHas('contatos_site', ['nome' => 'Ana Souza', 'email' => 'ana@exemplo.com']);
    }

    public function test_resposta_errada_e_recusada_e_nao_grava(): void
    {
        $this->postJson('/api/publico/contato', $this->dados(['desafio_resposta' => '99']))
            ->assertStatus(422)->assertJsonValidationErrors('desafio_resposta');

        $this->assertSame(0, ContatoSite::count());
    }

    public function test_token_forjado_ou_expirado_e_recusado(): void
    {
        $this->postJson('/api/publico/contato', $this->dados(['desafio_token' => 'lixo']))->assertStatus(422);
        $expirado = Crypt::encryptString('4|'.(time() - 1));
        $this->postJson('/api/publico/contato', $this->dados(['desafio_token' => $expirado, 'desafio_resposta' => '4']))->assertStatus(422);
    }

    public function test_campo_armadilha_preenchido_e_recusado(): void
    {
        $this->postJson('/api/publico/contato', $this->dados(['site' => 'http://spam']))->assertStatus(422);
    }

    public function test_campos_obrigatorios_e_email_valido(): void
    {
        $this->postJson('/api/publico/contato', $this->dados(['nome' => '', 'email' => 'x', 'mensagem' => '']))
            ->assertStatus(422)->assertJsonValidationErrors(['nome', 'email', 'mensagem']);
    }

    public function test_limite_de_envios_por_ip(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/publico/contato', $this->dados())->assertCreated();
        }
        $this->postJson('/api/publico/contato', $this->dados())->assertStatus(429);
    }
}

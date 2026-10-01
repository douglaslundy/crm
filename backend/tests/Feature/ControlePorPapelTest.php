<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Identity\Domain\Enums\Papel;
use App\Modules\Identity\Domain\Models\Usuario;
use App\Modules\Platform\Domain\Enums\Recurso;
use App\Modules\Platform\Domain\Models\Plano;
use App\Modules\Tenancy\Domain\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ControlePorPapelTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $plano = Plano::factory()->comLimites([Recurso::Produtos->value => 10, Recurso::Clientes->value => 10])->create();
        $this->tenant = Tenant::factory()->for($plano)->create();
    }

    private function usuario(Papel $papel): Usuario
    {
        return Usuario::factory()->for($this->tenant)->create(['papel' => $papel]);
    }

    /** @return array<string, array{0: Papel, 1: bool}> */
    public static function papeisEEmitente(): array
    {
        return [
            'proprietario escreve' => [Papel::Proprietario, true],
            'admin escreve' => [Papel::Admin, true],
            'fiscal escreve' => [Papel::Fiscal, true],
            'vendedor nao escreve' => [Papel::Vendedor, false],
            'leitura nao escreve' => [Papel::Leitura, false],
        ];
    }

    /** @dataProvider papeisEEmitente */
    public function test_escrita_no_emitente_por_papel(Papel $papel, bool $podeEscrever): void
    {
        $resposta = $this->spa()->actingAs($this->usuario($papel))
            ->putJson('/api/app/emitente/fiscal', ['regime_tributario' => 'SIMPLES']);

        $podeEscrever ? $resposta->assertOk() : $resposta->assertForbidden();
    }

    /** @return array<string, array{0: Papel, 1: bool}> */
    public static function papeisECadastros(): array
    {
        return [
            'proprietario escreve' => [Papel::Proprietario, true],
            'admin escreve' => [Papel::Admin, true],
            'fiscal escreve' => [Papel::Fiscal, true],
            'vendedor escreve' => [Papel::Vendedor, true],
            'leitura nao escreve' => [Papel::Leitura, false],
        ];
    }

    /** @dataProvider papeisECadastros */
    public function test_escrita_em_produtos_por_papel(Papel $papel, bool $podeEscrever): void
    {
        $resposta = $this->spa()->actingAs($this->usuario($papel))->postJson('/api/app/produtos', [
            'sku' => 'SKU-'.$papel->value, 'nome' => 'Item', 'unidade' => 'UN', 'preco_centavos' => 100,
        ]);

        $podeEscrever ? $resposta->assertCreated() : $resposta->assertForbidden();
    }

    /** @dataProvider papeisECadastros */
    public function test_escrita_em_clientes_por_papel(Papel $papel, bool $podeEscrever): void
    {
        $resposta = $this->spa()->actingAs($this->usuario($papel))->postJson('/api/app/clientes', [
            'tipo' => 'PF', 'nome' => 'Cliente de '.$papel->value,
        ]);

        $podeEscrever ? $resposta->assertCreated() : $resposta->assertForbidden();
    }
}

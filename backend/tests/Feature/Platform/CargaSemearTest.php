<?php

declare(strict_types=1);

namespace Tests\Feature\Platform;

use App\Modules\Tenancy\Domain\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CargaSemearTest extends TestCase
{
    use RefreshDatabase;

    private string $saida;

    protected function setUp(): void
    {
        parent::setUp();
        $this->saida = sys_get_temp_dir().'/contas-'.uniqid().'.json';
        config(['app.ambiente' => 'staging']);
    }

    protected function tearDown(): void
    {
        @unlink($this->saida);
        parent::tearDown();
    }

    /** @return array<string, mixed> */
    private function contas(): array
    {
        return json_decode((string) file_get_contents($this->saida), true, flags: JSON_THROW_ON_ERROR);
    }

    public function test_recusa_fora_do_staging(): void
    {
        config(['app.ambiente' => 'producao']);

        $this->artisan('carga:semear', ['--empresas' => 1, '--saida' => $this->saida])->assertFailed();
        $this->artisan('carga:limpar')->assertFailed();
        $this->assertSame(0, Tenant::query()->count());
    }

    public function test_semeia_empresas_com_tudo_para_a_carga(): void
    {
        $this->artisan('carga:semear', ['--empresas' => 2, '--saida' => $this->saida])->assertSuccessful();

        $dados = $this->contas();
        $this->assertSame('Senha123', $dados['senha']);
        $this->assertCount(2, $dados['empresas']);
        $e = $dados['empresas'][0];
        $this->assertCount(2, $e['usuarios']);
        $this->assertSame('ADMIN', $e['usuarios'][0]['papel']);
        $this->assertCount(10, $e['cliente_ids']);
        $this->assertCount(20, $e['negocio_ids']);
        $this->assertCount(3, $e['etapa_ids']);
        $this->assertStringStartsWith('test_', $e['chave_api']);
        $this->assertSame(2, Tenant::query()->where('razao_social', 'like', 'CARGA-%')->count());
    }

    public function test_dados_de_uma_empresa_nao_aparecem_na_outra(): void
    {
        $this->artisan('carga:semear', ['--empresas' => 2, '--saida' => $this->saida])->assertSuccessful();
        $dados = $this->contas();

        $this->assertEmpty(array_intersect($dados['empresas'][0]['cliente_ids'], $dados['empresas'][1]['cliente_ids']));
        $this->assertEmpty(array_intersect($dados['empresas'][0]['negocio_ids'], $dados['empresas'][1]['negocio_ids']));
        $this->assertNotSame($dados['empresas'][0]['tenant_id'], $dados['empresas'][1]['tenant_id']);
    }

    public function test_rodar_duas_vezes_exige_limpar_antes(): void
    {
        $this->artisan('carga:semear', ['--empresas' => 1, '--saida' => $this->saida])->assertSuccessful();
        $this->artisan('carga:semear', ['--empresas' => 1, '--saida' => $this->saida])->assertFailed();
        $this->assertSame(1, Tenant::query()->where('razao_social', 'like', 'CARGA-%')->count());
    }

    public function test_limpar_remove_so_as_empresas_de_carga_e_tudo_que_elas_criaram(): void
    {
        $outra = Tenant::factory()->create(['razao_social' => 'Empresa Real Ltda']);
        $this->artisan('carga:semear', ['--empresas' => 1, '--saida' => $this->saida])->assertSuccessful();
        $carga = $this->contas()['empresas'][0]['tenant_id'];
        $this->assertGreaterThan(0, DB::table('clientes')->where('tenant_id', $carga)->count());

        $this->artisan('carga:limpar')->assertSuccessful();

        $this->assertSame(0, Tenant::query()->where('razao_social', 'like', 'CARGA-%')->count());
        $this->assertSame(0, DB::table('clientes')->where('tenant_id', $carga)->count());
        $this->assertSame(0, DB::table('crm_negocios')->where('tenant_id', $carga)->count());
        $this->assertNotNull(Tenant::query()->find($outra->id));
    }
}

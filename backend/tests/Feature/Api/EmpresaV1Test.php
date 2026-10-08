<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Modules\Fiscal\Domain\Enums\CertificadoStatus;
use App\Modules\Fiscal\Domain\Models\Emitente;
use App\Modules\Identity\Domain\Enums\AmbienteDaChave;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fixtures\CenarioDeApi;
use Tests\TestCase;

class EmpresaV1Test extends TestCase
{
    use CenarioDeApi;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->montarApi();
    }

    private function emitenteComSegredos(): Emitente
    {
        return Emitente::factory()->for($this->tenant)->create([
            'uf' => 'SP', 'regime_tributario' => 'SIMPLES', 'inscricao_estadual' => '111222333444', 'cnae' => '4751201',
            'certificado_status' => CertificadoStatus::Valido,
            'certificado_pfx_encrypted' => 'PFX-SEGREDO-CRIPTOGRAFADO',
            'certificado_senha_encrypted' => 'SENHA-SEGREDO-CRIPTOGRAFADA',
            'certificado_titular' => 'EMPRESA TESTE LTDA:11444777000161',
            'csc_id_homologacao' => '000001', 'csc_token_homologacao_encrypted' => 'CSC-TOKEN-SEGREDO',
        ]);
    }

    public function test_devolve_os_dados_cadastrais_e_fiscais_do_emitente(): void
    {
        $this->emitenteComSegredos();

        $this->comoApi()->getJson('/api/v1/empresa')->assertOk()
            ->assertJsonPath('data.cnpj', $this->tenant->cnpj)
            ->assertJsonPath('data.razao_social', $this->tenant->razao_social)
            ->assertJsonPath('data.uf', 'SP')
            ->assertJsonPath('data.regime_tributario', 'SIMPLES')
            ->assertJsonPath('data.inscricao_estadual', '111222333444')
            ->assertJsonPath('data.ambiente_fiscal', 'HOMOLOGACAO')
            ->assertJsonPath('data.certificado_status', 'VALIDO');
    }

    public function test_nao_vaza_nenhum_segredo(): void
    {
        $this->emitenteComSegredos();

        $conteudo = (string) $this->comoApi()->getJson('/api/v1/empresa')->assertOk()->getContent();

        foreach (['SEGREDO', 'pfx', 'senha', 'csc', 'token', 'EMPRESA TESTE LTDA:11444777000161'] as $proibido) {
            $this->assertStringNotContainsStringIgnoringCase($proibido, $conteudo, "vazou: {$proibido}");
        }
    }

    public function test_ambiente_da_chave_reflete_live_e_test(): void
    {
        $this->emitenteComSegredos();
        [, $textoTest] = $this->novaChave($this->admin, AmbienteDaChave::Test);

        $this->comoApi()->getJson('/api/v1/empresa')->assertJsonPath('data.ambiente_da_chave', 'live');
        $this->comoApi($textoTest)->getJson('/api/v1/empresa')->assertJsonPath('data.ambiente_da_chave', 'test');
    }

    public function test_sem_emitente_devolve_so_os_dados_da_conta(): void
    {
        $this->comoApi()->getJson('/api/v1/empresa')->assertOk()
            ->assertJsonPath('data.cnpj', $this->tenant->cnpj)->assertJsonPath('data.uf', null)->assertJsonPath('data.ambiente_fiscal', null);
    }

    public function test_isolamento_nao_mostra_o_emitente_de_outro_tenant(): void
    {
        $this->emOutroTenant(fn ($outro) => Emitente::factory()->for($outro)->create(['uf' => 'RJ']));

        $this->comoApi()->getJson('/api/v1/empresa')->assertOk()->assertJsonPath('data.uf', null);
    }
}

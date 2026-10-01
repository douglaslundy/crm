<?php

declare(strict_types=1);

namespace Tests\Feature\Fiscal;

use App\Modules\Identity\Domain\Enums\Papel;
use App\Modules\Identity\Domain\Models\Usuario;
use App\Modules\Platform\Domain\Models\Plano;
use App\Modules\Tenancy\Domain\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class CertificadoTest extends TestCase
{
    use RefreshDatabase;

    /** Gera um .pfx autoassinado só para teste (nenhum dado real). */
    private function gerarPfx(string $senha): string
    {
        $chave = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $this->assertNotFalse($chave);
        $csr = openssl_csr_new(['commonName' => 'Empresa de Teste LTDA'], $chave);
        $this->assertNotFalse($csr);
        $cert = openssl_csr_sign($csr, null, $chave, 365);
        $this->assertNotFalse($cert);
        openssl_pkcs12_export($cert, $pfx, $chave, $senha);

        return $pfx;
    }

    public function test_upload_com_senha_certa_grava_validade_e_titular(): void
    {
        $tenant = Tenant::factory()->for(Plano::factory())->create();
        $admin = Usuario::factory()->for($tenant)->create(['papel' => Papel::Admin]);
        $pfx = $this->gerarPfx('senha-correta');
        $arquivo = UploadedFile::fake()->createWithContent('certificado.pfx', $pfx);

        $resposta = $this->spa()->actingAs($admin)->post('/api/app/emitente/certificado', [
            'arquivo' => $arquivo, 'senha' => 'senha-correta',
        ])->assertOk();

        $resposta->assertJsonPath('data.certificado_status', 'VALIDO');
        $this->assertSame('Empresa de Teste LTDA', $resposta->json('data.certificado_titular'));
        $this->assertDatabaseMissing('emitentes', ['certificado_senha_encrypted' => 'senha-correta']);
    }

    public function test_upload_com_senha_errada_e_recusado(): void
    {
        $tenant = Tenant::factory()->for(Plano::factory())->create();
        $admin = Usuario::factory()->for($tenant)->create(['papel' => Papel::Admin]);
        $pfx = $this->gerarPfx('senha-correta');
        $arquivo = UploadedFile::fake()->createWithContent('certificado.pfx', $pfx);

        $this->spa()->actingAs($admin)->post('/api/app/emitente/certificado', [
            'arquivo' => $arquivo, 'senha' => 'senha-errada',
        ])->assertStatus(422)->assertJsonPath('codigo', 'CERTIFICADO_SENHA_INVALIDA');
    }

    public function test_upload_de_arquivo_que_nao_e_pfx_e_recusado_sem_erro_500(): void
    {
        $tenant = Tenant::factory()->for(Plano::factory())->create();
        $admin = Usuario::factory()->for($tenant)->create(['papel' => Papel::Admin]);
        $arquivo = UploadedFile::fake()->createWithContent('documento.pdf', '%PDF-1.4 conteúdo qualquer, não é um certificado');

        $this->spa()->actingAs($admin)->post('/api/app/emitente/certificado', [
            'arquivo' => $arquivo, 'senha' => 'qualquer',
        ])->assertStatus(422)->assertJsonPath('codigo', 'CERTIFICADO_ARQUIVO_INVALIDO');
    }

    public function test_vendedor_nao_faz_upload_de_certificado(): void
    {
        $tenant = Tenant::factory()->for(Plano::factory())->create();
        $vendedor = Usuario::factory()->for($tenant)->create(['papel' => Papel::Vendedor]);
        $arquivo = UploadedFile::fake()->createWithContent('certificado.pfx', 'qualquer-coisa');

        $this->spa()->actingAs($vendedor)->post('/api/app/emitente/certificado', [
            'arquivo' => $arquivo, 'senha' => 'qualquer',
        ])->assertForbidden();
    }
}

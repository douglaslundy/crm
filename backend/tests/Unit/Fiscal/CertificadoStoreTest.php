<?php

declare(strict_types=1);

namespace Tests\Unit\Fiscal;

use App\Modules\Fiscal\Domain\Exceptions\EmissaoBloqueadaException;
use App\Modules\Fiscal\Domain\Models\Emitente;
use App\Modules\Fiscal\Infrastructure\NfePhp\CertificadoStore;
use Illuminate\Support\Facades\Crypt;
use Tests\TestCase;

class CertificadoStoreTest extends TestCase
{
    public function test_devolve_o_pfx_e_a_senha_decifrados(): void
    {
        $emitente = new Emitente;
        $emitente->forceFill([
            'certificado_pfx_encrypted' => Crypt::encryptString(base64_encode('BYTES-DO-PFX')),
            'certificado_senha_encrypted' => Crypt::encryptString('segredo'),
        ]);

        $this->assertSame(['pfx' => 'BYTES-DO-PFX', 'senha' => 'segredo'], (new CertificadoStore)->pfxESenha($emitente));
    }

    public function test_sem_certificado_bloqueia(): void
    {
        $this->expectException(EmissaoBloqueadaException::class);

        (new CertificadoStore)->pfxESenha(new Emitente);
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Infrastructure\NfePhp;

use App\Modules\Fiscal\Domain\Exceptions\EmissaoBloqueadaException;
use App\Modules\Fiscal\Domain\Models\Emitente;
use Illuminate\Support\Facades\Crypt;
use NFePHP\Common\Certificate;

/** O `.pfx` e a senha ficam criptografados no banco e só existem decifrados em memória, dentro desta chamada. */
final class CertificadoStore
{
    public function paraEmitente(Emitente $emitente): Certificate
    {
        if ($emitente->certificado_pfx_encrypted === null || $emitente->certificado_senha_encrypted === null) {
            throw new EmissaoBloqueadaException('FISCAL_CERTIFICADO_INVALIDO', 'Envie o certificado A1 em Configurações > Fiscal.');
        }

        $pfx = base64_decode(Crypt::decryptString($emitente->certificado_pfx_encrypted), true);
        if ($pfx === false) {
            throw new EmissaoBloqueadaException('FISCAL_CERTIFICADO_INVALIDO', 'O certificado armazenado está corrompido. Envie-o novamente.');
        }

        return Certificate::readPfx($pfx, Crypt::decryptString($emitente->certificado_senha_encrypted));
    }
}

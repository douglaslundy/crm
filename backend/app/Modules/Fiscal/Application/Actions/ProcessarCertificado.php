<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Application\Actions;

use App\Modules\Fiscal\Domain\DecisorDeStatusDoCertificado;
use App\Modules\Fiscal\Domain\Exceptions\CertificadoArquivoInvalidoException;
use App\Modules\Fiscal\Domain\Exceptions\CertificadoSenhaInvalidaException;
use App\Modules\Fiscal\Domain\Models\Emitente;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Crypt;

/**
 * Parse nativo com openssl, sem depender do `sped-nfe` (que só entra na F3).
 * O `.pfx` nunca toca o disco: é lido e descartado em memória.
 */
final class ProcessarCertificado
{
    public function executar(Emitente $emitente, string $conteudoBinario, string $senha): Emitente
    {
        $certs = [];
        if (! openssl_pkcs12_read($conteudoBinario, $certs, $senha)) {
            throw $this->interpretarFalha();
        }

        $info = openssl_x509_parse($certs['cert']);
        if ($info === false || ! isset($info['validTo_time_t'])) {
            throw new CertificadoArquivoInvalidoException;
        }

        $validade = CarbonImmutable::createFromTimestamp($info['validTo_time_t']);

        $emitente->update([
            'certificado_pfx_encrypted' => Crypt::encryptString(base64_encode($conteudoBinario)),
            'certificado_senha_encrypted' => Crypt::encryptString($senha),
            'certificado_validade' => $validade->toDateString(),
            'certificado_titular' => $info['subject']['CN'] ?? null,
            'certificado_status' => DecisorDeStatusDoCertificado::para($validade),
        ]);

        return $emitente;
    }

    private function interpretarFalha(): CertificadoSenhaInvalidaException|CertificadoArquivoInvalidoException
    {
        $mensagens = [];
        while (($erro = openssl_error_string()) !== false) {
            $mensagens[] = strtolower($erro);
        }
        $senhaErrada = array_filter($mensagens, static fn (string $m): bool => str_contains($m, 'mac verify failure'));

        return $senhaErrada !== [] ? new CertificadoSenhaInvalidaException : new CertificadoArquivoInvalidoException;
    }
}

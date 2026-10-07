<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Infrastructure\Nfse;

use App\Modules\Fiscal\Domain\Contracts\EmissorDeNfse;
use App\Modules\Fiscal\Domain\Emissao\NotaParaNfse;
use App\Modules\Fiscal\Domain\Emissao\ResultadoDeCancelamento;
use App\Modules\Fiscal\Domain\Emissao\ResultadoDeConsultaNfse;
use App\Modules\Fiscal\Domain\Emissao\ResultadoDeEmissaoNfse;
use App\Modules\Fiscal\Domain\Models\Emitente;
use App\Modules\Fiscal\Infrastructure\NfePhp\CertificadoStore;
use App\Modules\Tenancy\Domain\Models\Tenant;
use Nfse\Enums\TipoAmbiente;
use Nfse\Http\Exceptions\NfseApiException;
use Nfse\Http\NfseContext;
use Nfse\Nfse;
use Throwable;

/**
 * Fala com a ADN (Sistema Nacional NFS-e) pela `nfse-nacional/nfse-php`, com o certificado A1 do próprio CNPJ.
 * Sem teste automatizado contra a ADN real (exige certificado e município aderido): coberto pelo roteiro de homologação.
 * ATENÇÃO: a lib desliga a verificação TLS nos próprios clientes HTTP (pendência de segurança, ver TAREFAS.md).
 */
final class NfseNacionalEmissor implements EmissorDeNfse
{
    public function __construct(
        private readonly CertificadoStore $certificados,
        private readonly MontadorDeDps $montador,
        private readonly InterpretadorDeNfse $interpretador,
        private readonly MontadorDeEventoDeCancelamento $eventos,
    ) {}

    public function consultarDps(Emitente $emitente, NotaParaNfse $nota): ResultadoDeConsultaNfse
    {
        $contribuinte = $this->nfse($emitente, $nota->ambiente)->contribuinte();
        $idDps = MontadorDeDps::idDaDps($nota);
        try {
            if (! $contribuinte->verificarDps($idDps)) {
                return ResultadoDeConsultaNfse::naoEncontrada();
            }
            $chave = $this->interpretador->chaveDe($contribuinte->consultarDps($idDps)->chaveAcesso);
        } catch (Throwable $e) {
            return ResultadoDeConsultaNfse::erro('Não foi possível confirmar se a DPS já existe na ADN: '.mb_substr($e->getMessage(), 0, 300));
        }
        if ($chave === null) {
            return ResultadoDeConsultaNfse::erro('A ADN conhece a DPS, mas não devolveu uma chave de acesso de 50 dígitos.');
        }

        return $this->consultarPorChave($emitente, $chave, $nota->ambiente);
    }

    public function emitir(Emitente $emitente, NotaParaNfse $nota): ResultadoDeEmissaoNfse
    {
        $contribuinte = $this->nfse($emitente, $nota->ambiente)->contribuinte();
        try {
            $resultado = $contribuinte->emitir($this->montador->montar($nota));
        } catch (NfseApiException $e) {
            return $this->interpretador->rejeicao($e)
                ?? ResultadoDeEmissaoNfse::erro('Falha de comunicação com a ADN ao emitir. Tente novamente: o número foi preservado. ('.mb_substr($e->getMessage(), 0, 200).')');
        }

        return $this->interpretador->emissao($resultado);
    }

    public function consultarPorChave(Emitente $emitente, string $chave, string $ambiente): ResultadoDeConsultaNfse
    {
        $contribuinte = $this->nfse($emitente, $ambiente)->contribuinte();
        try {
            $dados = $contribuinte->consultar($chave);
            if ($dados === null) {
                return $this->interpretador->consulta(null, false);
            }
            // Cancelamento é um evento separado (101101), não um cStat do corpo da NFS-e.
            $cancelada = $this->interpretador->existeEventoDeCancelamento(fn () => $contribuinte->consultarEvento($chave, 101101, 1));
        } catch (Throwable $e) {
            return ResultadoDeConsultaNfse::erro('Não foi possível confirmar se a NFS-e está cancelada: '.mb_substr($e->getMessage(), 0, 300));
        }

        return $this->interpretador->consulta($dados, $cancelada);
    }

    public function cancelar(Emitente $emitente, string $chave, string $justificativa, string $ambiente): ResultadoDeCancelamento
    {
        $tenant = Tenant::query()->findOrFail($emitente->tenant_id);
        $evento = $this->eventos->montar($chave, $justificativa, $ambiente, preg_replace('/\D/', '', $tenant->cnpj) ?? '');
        try {
            $resposta = $this->nfse($emitente, $ambiente)->contribuinte()->cancelar($evento);
        } catch (NfseApiException $e) {
            $recusa = $this->interpretador->rejeicao($e);
            if ($recusa === null) {
                throw $e; // rede/timeout: a nota permanece CANCELANDO e a reconciliação decide
            }

            return ResultadoDeCancelamento::recusado((string) $recusa->cstat, (string) $recusa->motivo);
        }

        return $this->interpretador->cancelamento($resposta);
    }

    /** O PFX decifrado fica só em memória: a lib o grava num temporário e o apaga no destrutor. */
    private function nfse(Emitente $emitente, string $ambiente): Nfse
    {
        ['pfx' => $pfx, 'senha' => $senha] = $this->certificados->pfxESenha($emitente);

        return new Nfse(new NfseContext(
            ambiente: $ambiente === 'PRODUCAO' ? TipoAmbiente::Producao : TipoAmbiente::Homologacao,
            certificatePath: null,
            certificatePassword: $senha,
            codigoMunicipio: (string) $emitente->codigo_ibge,
            certificateContent: $pfx,
        ));
    }
}

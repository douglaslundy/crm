<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Infrastructure\Nfse;

use App\Modules\Fiscal\Domain\Emissao\ResultadoDeCancelamento;
use App\Modules\Fiscal\Domain\Emissao\ResultadoDeConsultaNfse;
use App\Modules\Fiscal\Domain\Emissao\ResultadoDeEmissaoNfse;
use App\Modules\Fiscal\Domain\Enums\StatusNota;
use Illuminate\Support\Str;
use Nfse\Dto\Http\RegistroEventoResponse;
use Nfse\Dto\Nfse\NfseData;
use Nfse\Enums\CodigoStatus;
use Nfse\Http\Exceptions\NfseApiException;
use RuntimeException;

/** Traduz as respostas da `nfse-php` para os resultados do domínio. Nunca chuta AUTORIZADA nem "não encontrada". */
final class InterpretadorDeNfse
{
    /** cStat 100, 102, 103 e 107 (gerada, decisão judicial, avulsa, MEI; a 1.23 da lib não tem mais o 101): os únicos que valem como autorizada. */
    private const AUTORIZADOS = [
        CodigoStatus::NfseGerada, CodigoStatus::NfseDecisaoJudicial,
        CodigoStatus::NfseAvulsa, CodigoStatus::NfseMei,
    ];

    /** O `Id` do infNFSe vem com o prefixo "NFS" (um ID XML começa com letra); a chave são os 50 dígitos depois dele. */
    public function chaveDe(?string $id): ?string
    {
        if ($id === null) {
            return null;
        }
        if (preg_match('/^NFS(\d{50})$/', $id, $m) === 1) {
            return $m[1];
        }

        return preg_match('/^\d{50}$/', $id) === 1 ? $id : null;
    }

    public function emissao(NfseData $r): ResultadoDeEmissaoNfse
    {
        $status = $r->infNfse?->codigoStatus;
        $descricaoDoStatus = $status === null ? 'ausente' : (string) $status->value;
        if ($status === null || ! in_array($status, self::AUTORIZADOS, true)) {
            return ResultadoDeEmissaoNfse::erro("NFS-e em status não reconhecido (cStat={$descricaoDoStatus}); não é possível classificar com segurança como autorizada.");
        }
        $chave = $this->chaveDe($r->infNfse->id);
        $numero = $r->infNfse->numeroNfse;
        if ($chave === null || $numero === null || $r->nfseXml === null) {
            return ResultadoDeEmissaoNfse::erro('A ADN autorizou a NFS-e, mas a resposta veio sem a chave de 50 dígitos, o número ou o XML. Consulte a nota no portal nacional antes de emitir de novo.');
        }

        return ResultadoDeEmissaoNfse::autorizada($chave, $numero, $r->nfseXml, (string) $status->value);
    }

    /** `consultar` da lib devolve nulo tanto para "não encontrada" quanto para falha de API: aqui isso é incerteza (Erro). */
    public function consulta(?NfseData $r, bool $cancelada): ResultadoDeConsultaNfse
    {
        if ($r === null) {
            return ResultadoDeConsultaNfse::erro('NFS-e não encontrada ou falha ao consultar (a biblioteca não distingue os dois casos).');
        }
        if ($cancelada) {
            return ResultadoDeConsultaNfse::cancelada($this->chaveDe($r->infNfse?->id));
        }
        $emissao = $this->emissao($r);

        return $emissao->status === StatusNota::Autorizada
            ? ResultadoDeConsultaNfse::autorizada((string) $emissao->chave, (string) $emissao->numeroNfse, (string) $emissao->xmlAutorizado, (string) $emissao->cstat)
            : ResultadoDeConsultaNfse::erro((string) $emissao->mensagemErro);
    }

    /** Só é rejeição quando a ADN respondeu com `erros`; o resto (rede, timeout) é incerteza e quem chama decide. */
    public function rejeicao(NfseApiException $e): ?ResultadoDeEmissaoNfse
    {
        $erros = $e->getErrors();
        if ($erros === []) {
            return null;
        }
        $codigo = trim((string) ($erros[0]->codigo ?? ''));
        $motivo = implode(' | ', array_map(
            static fn ($m): string => trim(($m->codigo !== null && $m->codigo !== '' ? $m->codigo.': ' : '').($m->descricao ?? $m->mensagem ?? '')),
            $erros,
        ));

        return ResultadoDeEmissaoNfse::rejeitada($codigo === '' ? 'ADN' : $codigo, mb_substr($motivo, 0, 500));
    }

    /**
     * `$consultarEvento` faz GET /nfse/{chave}/eventos/101101/1 e lança `NfseApiException` fora de 2xx.
     * 2xx = há evento; 404 COM corpo JSON da API = não há; qualquer outra coisa (404 em HTML, sem corpo, 5xx) é incerteza e sobe.
     *
     * @param  callable(): mixed  $consultarEvento
     */
    public function existeEventoDeCancelamento(callable $consultarEvento): bool
    {
        try {
            $consultarEvento();

            return true;
        } catch (NfseApiException $e) {
            $corpo = $e->getRawResponse();
            if ($e->getCode() === 404 && $corpo !== null && is_array(json_decode($corpo, true))) {
                return false;
            }

            throw $e;
        }
    }

    /** Sem o XML do evento o cancelamento NÃO está confirmado: a nota segue CANCELANDO e a reconciliação decide. */
    public function cancelamento(RegistroEventoResponse $r): ResultadoDeCancelamento
    {
        if ($r->eventoXmlGZipB64 === null || $r->eventoXmlGZipB64 === '') {
            throw new RuntimeException('Cancelamento não confirmado: resposta da ADN sem o XML do evento.');
        }
        $xml = gzdecode((string) base64_decode($r->eventoXmlGZipB64, true));

        return new ResultadoDeCancelamento(true, '101101', 'Cancelamento de NFS-e registrado', null, $xml === false ? $r->eventoXmlGZipB64 : $xml);
    }

    /** TSCodJustCanc: 1 erro na emissão, 2 serviço não prestado, 9 outros (padrão seguro; o texto inteiro vai em xMotivo). */
    public function codigoDoMotivo(string $texto): string
    {
        // ascii() antes de lower(): strtolower não lida com "Ç" e "Ã" maiúsculos.
        $m = strtolower(Str::ascii($texto));
        if (str_contains($m, 'erro') || str_contains($m, 'engano') || str_contains($m, 'equivoco')) {
            return '1';
        }
        if (str_contains($m, 'nao prestado') || str_contains($m, 'nao realizado') || str_contains($m, 'nao executado')) {
            return '2';
        }

        return '9';
    }
}

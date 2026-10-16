<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Infrastructure\Simulado;

use App\Modules\Fiscal\Domain\Contracts\EmissorDeNfe;
use App\Modules\Fiscal\Domain\Emissao\NotaParaEmissao;
use App\Modules\Fiscal\Domain\Emissao\ResultadoDeCancelamento;
use App\Modules\Fiscal\Domain\Emissao\ResultadoDeConsulta;
use App\Modules\Fiscal\Domain\Emissao\ResultadoDeEmissao;
use App\Modules\Fiscal\Domain\Emissao\ResultadoDeEpec;
use App\Modules\Fiscal\Domain\Emissao\ResultadoDeInutilizacao;
use App\Modules\Fiscal\Domain\Emissao\XmlAssinado;
use App\Modules\Fiscal\Domain\Enums\ModeloDocumento;
use App\Modules\Fiscal\Domain\Models\Emitente;
use Carbon\CarbonImmutable;
use LogicException;

/**
 * Só para teste de carga: autoriza tudo, sem rede e sem certificado. O XML é sintético e NÃO é válido contra o XSD.
 * O provider só o registra com APP_AMBIENTE=staging.
 */
final class EmissorDeNfeSimulado implements EmissorDeNfe
{
    public function __construct(private readonly int $latenciaMs = 0) {}

    public function preparar(Emitente $emitente, NotaParaEmissao $nota): XmlAssinado
    {
        // 2 + 10 + 32 = 44 dígitos. O crc32 do emitente separa empresas que usam o mesmo número.
        $chave = '35'.str_pad((string) crc32($emitente->id), 10, '0', STR_PAD_LEFT).str_pad((string) $nota->numero, 32, '0', STR_PAD_LEFT);

        return new XmlAssinado($chave, '<NFe>simulado</NFe>');
    }

    public function prepararContingencia(Emitente $emitente, NotaParaEmissao $nota, string $justificativa, CarbonImmutable $em): XmlAssinado
    {
        throw new LogicException('Contingência não é simulada.');
    }

    public function prepararEpec(Emitente $emitente, NotaParaEmissao $nota, string $justificativa, CarbonImmutable $em): XmlAssinado
    {
        throw new LogicException('EPEC não é simulado.');
    }

    public function registrarEpec(Emitente $emitente, NotaParaEmissao $nota, XmlAssinado $xml): ResultadoDeEpec
    {
        throw new LogicException('EPEC não é simulado.');
    }

    public function transmitir(Emitente $emitente, NotaParaEmissao $nota, XmlAssinado $xml): ResultadoDeEmissao
    {
        $this->esperar();

        return ResultadoDeEmissao::autorizada('135'.str_pad((string) random_int(0, 999999999999), 12, '0', STR_PAD_LEFT), '<nfeProc>simulado</nfeProc>');
    }

    public function consultarPorChave(Emitente $emitente, string $chave, ?string $xmlEnviado, string $ambiente): ResultadoDeConsulta
    {
        $this->esperar();

        return ResultadoDeConsulta::naoEncontrada();
    }

    public function cancelar(Emitente $emitente, string $chave, string $protocolo, string $justificativa, string $ambiente, string $xmlAutorizado): ResultadoDeCancelamento
    {
        $this->esperar();

        return ResultadoDeCancelamento::aceito('135000000000099', '<nfeProc>cancelada-simulada</nfeProc>');
    }

    public function inutilizar(Emitente $emitente, ModeloDocumento $modelo, int $serie, int $numeroInicial, int $numeroFinal, string $justificativa, string $ambiente): ResultadoDeInutilizacao
    {
        $this->esperar();

        return ResultadoDeInutilizacao::inutilizada('135000000000098', '<retInutNFe/>');
    }

    private function esperar(): void
    {
        if ($this->latenciaMs > 0) {
            usleep($this->latenciaMs * 1000);
        }
    }
}

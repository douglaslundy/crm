<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use App\Modules\Fiscal\Domain\Contracts\EmissorDeNfe;
use App\Modules\Fiscal\Domain\Emissao\NotaParaEmissao;
use App\Modules\Fiscal\Domain\Emissao\ResultadoDeCancelamento;
use App\Modules\Fiscal\Domain\Emissao\ResultadoDeConsulta;
use App\Modules\Fiscal\Domain\Emissao\ResultadoDeEmissao;
use App\Modules\Fiscal\Domain\Emissao\ResultadoDeInutilizacao;
use App\Modules\Fiscal\Domain\Emissao\XmlAssinado;
use App\Modules\Fiscal\Domain\Models\Emitente;
use Throwable;

final class EmissorFake implements EmissorDeNfe
{
    /** @var list<string> */
    public array $chamadas = [];

    /** @var list<string> Ambiente recebido em cada consulta, cancelamento e inutilização, em ordem. */
    public array $ambientes = [];

    public ?Throwable $falharEmPreparar = null;

    public ?Throwable $falharEmTransmitir = null;

    public ?Throwable $falharEmCancelar = null;

    public ?Throwable $falharEmInutilizar = null;

    /** @var list<ResultadoDeEmissao> */
    private array $emissoes = [];

    /** @var list<ResultadoDeConsulta> */
    private array $consultas = [];

    /** @var list<ResultadoDeCancelamento> */
    private array $cancelamentos = [];

    /** @var list<ResultadoDeInutilizacao> */
    private array $inutilizacoes = [];

    public function enfileirarEmissao(ResultadoDeEmissao ...$resultados): void
    {
        array_push($this->emissoes, ...$resultados);
    }

    public function enfileirarConsulta(ResultadoDeConsulta ...$resultados): void
    {
        array_push($this->consultas, ...$resultados);
    }

    public function enfileirarCancelamento(ResultadoDeCancelamento ...$resultados): void
    {
        array_push($this->cancelamentos, ...$resultados);
    }

    public function enfileirarInutilizacao(ResultadoDeInutilizacao ...$resultados): void
    {
        array_push($this->inutilizacoes, ...$resultados);
    }

    public function preparar(Emitente $emitente, NotaParaEmissao $nota): XmlAssinado
    {
        $this->chamadas[] = 'preparar';
        if ($this->falharEmPreparar !== null) {
            throw $this->falharEmPreparar;
        }

        return new XmlAssinado('35'.str_pad((string) $nota->numero, 42, '0', STR_PAD_LEFT), '<NFe>fake</NFe>');
    }

    public function transmitir(Emitente $emitente, NotaParaEmissao $nota, XmlAssinado $xml): ResultadoDeEmissao
    {
        $this->chamadas[] = 'transmitir';
        if ($this->falharEmTransmitir !== null) {
            throw $this->falharEmTransmitir;
        }

        return array_shift($this->emissoes) ?? ResultadoDeEmissao::autorizada('135000000000001', '<nfeProc>fake</nfeProc>');
    }

    public function consultarPorChave(Emitente $emitente, string $chave, ?string $xmlEnviado, string $ambiente): ResultadoDeConsulta
    {
        $this->chamadas[] = 'consultar';
        $this->ambientes[] = $ambiente;

        return array_shift($this->consultas) ?? ResultadoDeConsulta::naoEncontrada();
    }

    public function cancelar(Emitente $emitente, string $chave, string $protocolo, string $justificativa, string $ambiente, string $xmlAutorizado): ResultadoDeCancelamento
    {
        $this->chamadas[] = 'cancelar';
        $this->ambientes[] = $ambiente;
        if ($this->falharEmCancelar !== null) {
            throw $this->falharEmCancelar;
        }

        return array_shift($this->cancelamentos) ?? ResultadoDeCancelamento::aceito('135260000000099', '<nfeProc>cancelada</nfeProc>');
    }

    public function inutilizar(Emitente $emitente, int $serie, int $numeroInicial, int $numeroFinal, string $justificativa, string $ambiente): ResultadoDeInutilizacao
    {
        $this->chamadas[] = 'inutilizar';
        $this->ambientes[] = $ambiente;
        if ($this->falharEmInutilizar !== null) {
            throw $this->falharEmInutilizar;
        }

        return array_shift($this->inutilizacoes) ?? ResultadoDeInutilizacao::inutilizada('135260000000098', '<retInutNFe/>');
    }
}

<?php

declare(strict_types=1);

namespace Tests\Fixtures;

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
use Throwable;

final class EmissorFake implements EmissorDeNfe
{
    /** @var list<string> */
    public array $chamadas = [];

    /** @var list<ModeloDocumento> */
    public array $modelosInutilizados = [];

    /** @var list<string> Ambiente recebido em cada consulta, cancelamento e inutilização, em ordem. */
    public array $ambientes = [];

    public ?Throwable $falharEmPreparar = null;

    public ?Throwable $falharEmTransmitir = null;

    public ?Throwable $falharEmConsultar = null;

    /** @var list<string> XMLs gerados em contingência, em ordem. */
    public array $contingencias = [];

    public ?Throwable $falharEmEpec = null;

    /** Roda no meio do `registrarEpec`, antes de responder: simula o que acontece com a nota durante o envio. */
    public ?\Closure $aoRegistrarEpec = null;

    /** @var list<string> Justificativas dos XMLs gerados em EPEC, em ordem. */
    public array $epecs = [];

    public ?Throwable $falharEmCancelar = null;

    public ?Throwable $falharEmInutilizar = null;

    /** @var list<ResultadoDeEmissao> */
    private array $emissoes = [];

    /** @var list<ResultadoDeConsulta> */
    private array $consultas = [];

    /** @var list<ResultadoDeEpec> */
    private array $registrosEpec = [];

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

    public function prepararContingencia(Emitente $emitente, NotaParaEmissao $nota, string $justificativa, CarbonImmutable $em): XmlAssinado
    {
        $this->chamadas[] = 'prepararContingencia';
        $this->contingencias[] = $justificativa;

        return new XmlAssinado('35'.str_pad((string) $nota->numero, 33, '0', STR_PAD_LEFT).'9'.str_repeat('7', 9), '<NFe>contingencia</NFe>');
    }

    public function enfileirarEpec(ResultadoDeEpec ...$resultados): void
    {
        array_push($this->registrosEpec, ...$resultados);
    }

    public function prepararEpec(Emitente $emitente, NotaParaEmissao $nota, string $justificativa, CarbonImmutable $em): XmlAssinado
    {
        $this->chamadas[] = 'prepararEpec';
        $this->epecs[] = $justificativa;

        return new XmlAssinado('35'.str_pad((string) $nota->numero, 32, '0', STR_PAD_LEFT).'4'.str_repeat('7', 9), '<NFe>epec</NFe>');
    }

    public function registrarEpec(Emitente $emitente, NotaParaEmissao $nota, XmlAssinado $xml): ResultadoDeEpec
    {
        $this->chamadas[] = 'registrarEpec';
        if ($this->falharEmEpec !== null) {
            throw $this->falharEmEpec;
        }
        if ($this->aoRegistrarEpec !== null) {
            ($this->aoRegistrarEpec)();
        }

        return array_shift($this->registrosEpec) ?? ResultadoDeEpec::registrado('891260000000001', '<retEnvEvento>fake</retEnvEvento>');
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
        if ($this->falharEmConsultar !== null) {
            throw $this->falharEmConsultar;
        }
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

    public function inutilizar(Emitente $emitente, ModeloDocumento $modelo, int $serie, int $numeroInicial, int $numeroFinal, string $justificativa, string $ambiente): ResultadoDeInutilizacao
    {
        $this->chamadas[] = 'inutilizar';
        $this->modelosInutilizados[] = $modelo;
        $this->ambientes[] = $ambiente;
        if ($this->falharEmInutilizar !== null) {
            throw $this->falharEmInutilizar;
        }

        return array_shift($this->inutilizacoes) ?? ResultadoDeInutilizacao::inutilizada('135260000000098', '<retInutNFe/>');
    }
}

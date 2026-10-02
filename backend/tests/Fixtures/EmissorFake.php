<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use App\Modules\Fiscal\Domain\Contracts\EmissorDeNfe;
use App\Modules\Fiscal\Domain\Emissao\NotaParaEmissao;
use App\Modules\Fiscal\Domain\Emissao\ResultadoDeConsulta;
use App\Modules\Fiscal\Domain\Emissao\ResultadoDeEmissao;
use App\Modules\Fiscal\Domain\Emissao\XmlAssinado;
use App\Modules\Fiscal\Domain\Models\Emitente;
use Throwable;

final class EmissorFake implements EmissorDeNfe
{
    /** @var list<string> */
    public array $chamadas = [];

    public ?Throwable $falharEmPreparar = null;

    public ?Throwable $falharEmTransmitir = null;

    /** @var list<ResultadoDeEmissao> */
    private array $emissoes = [];

    /** @var list<ResultadoDeConsulta> */
    private array $consultas = [];

    public function enfileirarEmissao(ResultadoDeEmissao ...$resultados): void
    {
        array_push($this->emissoes, ...$resultados);
    }

    public function enfileirarConsulta(ResultadoDeConsulta ...$resultados): void
    {
        array_push($this->consultas, ...$resultados);
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

    public function consultarPorChave(Emitente $emitente, string $chave, ?string $xmlEnviado): ResultadoDeConsulta
    {
        $this->chamadas[] = 'consultar';

        return array_shift($this->consultas) ?? ResultadoDeConsulta::naoEncontrada();
    }
}

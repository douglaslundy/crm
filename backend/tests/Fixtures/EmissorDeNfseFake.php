<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use App\Modules\Fiscal\Domain\Contracts\EmissorDeNfse;
use App\Modules\Fiscal\Domain\Emissao\NotaParaNfse;
use App\Modules\Fiscal\Domain\Emissao\ResultadoDeCancelamento;
use App\Modules\Fiscal\Domain\Emissao\ResultadoDeConsultaNfse;
use App\Modules\Fiscal\Domain\Emissao\ResultadoDeEmissaoNfse;
use App\Modules\Fiscal\Domain\Models\Emitente;
use Throwable;

final class EmissorDeNfseFake implements EmissorDeNfse
{
    /** 50 dígitos, como a chave da NFS-e Nacional (sem o prefixo "NFS"). */
    public const CHAVE = '3550308211444777000161'.'1111111111111111111111111111';

    /** @var list<string> */
    public array $chamadas = [];

    public ?Throwable $falharEmEmitir = null;

    public ?Throwable $falharEmCancelar = null;

    public ?Throwable $falharEmConsultar = null;

    /** @var list<ResultadoDeConsultaNfse> */
    private array $consultasDps = [];

    /** @var list<ResultadoDeEmissaoNfse> */
    private array $emissoes = [];

    /** @var list<ResultadoDeConsultaNfse> */
    private array $consultasPorChave = [];

    /** @var list<ResultadoDeCancelamento> */
    private array $cancelamentos = [];

    public function enfileirarConsultaDps(ResultadoDeConsultaNfse ...$r): void
    {
        array_push($this->consultasDps, ...$r);
    }

    public function enfileirarEmissao(ResultadoDeEmissaoNfse ...$r): void
    {
        array_push($this->emissoes, ...$r);
    }

    public function enfileirarConsultaPorChave(ResultadoDeConsultaNfse ...$r): void
    {
        array_push($this->consultasPorChave, ...$r);
    }

    public function enfileirarCancelamento(ResultadoDeCancelamento ...$r): void
    {
        array_push($this->cancelamentos, ...$r);
    }

    public function consultarDps(Emitente $emitente, NotaParaNfse $nota): ResultadoDeConsultaNfse
    {
        $this->chamadas[] = 'consultarDps';
        if ($this->falharEmConsultar !== null) {
            throw $this->falharEmConsultar;
        }

        return array_shift($this->consultasDps) ?? ResultadoDeConsultaNfse::naoEncontrada();
    }

    public function emitir(Emitente $emitente, NotaParaNfse $nota): ResultadoDeEmissaoNfse
    {
        $this->chamadas[] = 'emitir';
        if ($this->falharEmEmitir !== null) {
            throw $this->falharEmEmitir;
        }

        return array_shift($this->emissoes) ?? ResultadoDeEmissaoNfse::autorizada(self::CHAVE, (string) $nota->numeroDps, '<NFSe>fake</NFSe>');
    }

    public function consultarPorChave(Emitente $emitente, string $chave, string $ambiente): ResultadoDeConsultaNfse
    {
        $this->chamadas[] = 'consultarPorChave';
        if ($this->falharEmConsultar !== null) {
            throw $this->falharEmConsultar;
        }

        return array_shift($this->consultasPorChave) ?? ResultadoDeConsultaNfse::autorizada($chave, '1', '<NFSe>fake</NFSe>');
    }

    public function cancelar(Emitente $emitente, string $chave, string $justificativa, string $ambiente): ResultadoDeCancelamento
    {
        $this->chamadas[] = 'cancelar';
        if ($this->falharEmCancelar !== null) {
            throw $this->falharEmCancelar;
        }

        return array_shift($this->cancelamentos)
            ?? new ResultadoDeCancelamento(true, '101101', 'Cancelamento de NFS-e registrado', null, '<evento>fake</evento>');
    }
}

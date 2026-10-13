<?php

declare(strict_types=1);

namespace App\Modules\Crm\Application\Relatorio;

use App\Modules\Crm\Domain\Exceptions\RegraDoCrmException;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/** Intervalo de datas do relatório, sempre no fuso do negócio; as bordas viram UTC só na consulta. */
final class PeriodoDoRelatorio
{
    private const MAXIMO_DE_DIAS = 366;

    private function __construct(
        public readonly CarbonImmutable $de,
        public readonly CarbonImmutable $ate,
    ) {}

    public static function entre(string $de, string $ate): self
    {
        $inicio = self::dia($de);
        $fim = self::dia($ate);

        if ($fim->lt($inicio)) {
            throw RegraDoCrmException::periodoInvalido('A data final não pode ser anterior à inicial.');
        }

        if ($inicio->diffInDays($fim) > self::MAXIMO_DE_DIAS - 1) {
            throw RegraDoCrmException::periodoInvalido('O período máximo é de 366 dias.');
        }

        return new self($inicio, $fim->endOfDay());
    }

    public static function doMes(CarbonInterface $referencia): self
    {
        $dia = CarbonImmutable::instance($referencia)->setTimezone((string) config('app.fuso_negocio'));

        return new self($dia->startOfMonth(), $dia->endOfMonth());
    }

    public function inicioUtc(): CarbonImmutable
    {
        return $this->de->utc();
    }

    public function fimUtc(): CarbonImmutable
    {
        return $this->ate->utc();
    }

    public function inicioData(): string
    {
        return $this->de->toDateString();
    }

    public function fimData(): string
    {
        return $this->ate->toDateString();
    }

    /** Dia 1 do mês, chave da meta. */
    public function mes(): string
    {
        return $this->de->startOfMonth()->toDateString();
    }

    public function ehMesInteiro(): bool
    {
        return $this->de->day === 1 && $this->ate->toDateString() === $this->de->endOfMonth()->toDateString();
    }

    private static function dia(string $texto): CarbonImmutable
    {
        $mensagem = 'Informe as datas do período no formato AAAA-MM-DD.';

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $texto) !== 1) {
            throw RegraDoCrmException::periodoInvalido($mensagem);
        }

        try {
            $dia = CarbonImmutable::createFromFormat('!Y-m-d', $texto, (string) config('app.fuso_negocio'));
        } catch (\Throwable) {
            throw RegraDoCrmException::periodoInvalido($mensagem);
        }

        // `2026-02-31` não falha na criação: vira 3 de março. A comparação pega o estouro.
        if (! $dia instanceof CarbonImmutable || $dia->format('Y-m-d') !== $texto) {
            throw RegraDoCrmException::periodoInvalido($mensagem);
        }

        return $dia;
    }
}

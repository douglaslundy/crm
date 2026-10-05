<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Application;

use App\Modules\Fiscal\Domain\Enums\StatusNota;
use App\Modules\Fiscal\Domain\Models\Emitente;
use App\Modules\Fiscal\Domain\Models\Nota;
use Carbon\CarbonImmutable;

/**
 * Números do painel fiscal do tenant (o Global Scope isola). Vale só o ambiente atual do emitente.
 * Meses em America/Sao_Paulo; só o zero real (nenhuma nota) vira 0.
 */
final class ResumoFiscal
{
    private const FUSO = 'America/Sao_Paulo';

    /** Emitidas contam no valor; cancelada sai da conta. */
    private const STATUS_DE_VALOR = [StatusNota::Autorizada, StatusNota::Cancelando];

    /** Status que já passaram pela SEFAZ: o mês é o da emissão. Os demais usam a data de criação. */
    private const STATUS_EMITIDOS = [StatusNota::Autorizada, StatusNota::Cancelando, StatusNota::Cancelada];

    /** @return array<string, mixed> */
    public function executar(?CarbonImmutable $agora = null): array
    {
        $agora = ($agora ?? CarbonImmutable::now())->setTimezone(self::FUSO);
        $emitente = Emitente::query()->firstOrCreate([], []);
        $ambiente = $emitente->ambiente_fiscal->value;

        $inicioDoMes = $agora->startOfMonth();
        [$de, $ate] = $this->limites($inicioDoMes);

        return [
            'ambiente' => $ambiente,
            'certificado' => $this->certificado($emitente, $agora),
            'mes' => [
                'periodo' => $inicioDoMes->format('Y-m'),
                'notas' => $this->notasDoMes($ambiente, $de, $ate),
                'valor_emitido_centavos' => $this->valorEmitido($ambiente, $de, $ate),
            ],
            'faturamento_12_meses' => $this->faturamento($ambiente, $inicioDoMes),
            'rejeicoes' => $this->rejeicoes($ambiente),
        ];
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} limites do mês no fuso da aplicação (como o banco guarda). */
    private function limites(CarbonImmutable $inicioDoMes): array
    {
        $fusoDoBanco = (string) config('app.timezone');

        return [$inicioDoMes->setTimezone($fusoDoBanco), $inicioDoMes->endOfMonth()->setTimezone($fusoDoBanco)];
    }

    /** @return array{status: string, validade: ?string, dias_restantes: ?int} */
    private function certificado(Emitente $emitente, CarbonImmutable $agora): array
    {
        $validade = $emitente->certificado_validade;
        $dias = $validade === null
            ? null
            : (int) $agora->startOfDay()->diffInDays(CarbonImmutable::parse($validade->toDateString(), self::FUSO)->startOfDay(), false);

        return [
            'status' => $emitente->certificado_status->value,
            'validade' => $validade?->toDateString(),
            'dias_restantes' => $dias,
        ];
    }

    private function valorEmitido(string $ambiente, CarbonImmutable $de, CarbonImmutable $ate): int
    {
        return (int) Nota::query()->where('ambiente', $ambiente)->whereIn('status', self::STATUS_DE_VALOR)
            ->whereBetween('emitido_em', [$de, $ate])->sum('total_centavos');
    }

    /** @return list<array{modelo: string, status: string, quantidade: int}> */
    private function notasDoMes(string $ambiente, CarbonImmutable $de, CarbonImmutable $ate): array
    {
        $emitidas = Nota::query()->where('ambiente', $ambiente)->whereIn('status', self::STATUS_EMITIDOS)
            ->whereBetween('emitido_em', [$de, $ate]);
        // Rascunho ainda não tem ambiente: conta pelo mês de criação, sem filtro de ambiente.
        $demais = Nota::query()->whereNotIn('status', self::STATUS_EMITIDOS)
            ->where(fn ($q) => $q->where('ambiente', $ambiente)->orWhereNull('ambiente'))
            ->whereBetween('created_at', [$de, $ate]);

        $linhas = [];
        foreach ([$emitidas, $demais] as $consulta) {
            foreach ($consulta->selectRaw('modelo, status, count(*) as quantidade')->groupBy('modelo', 'status')->get() as $linha) {
                $linhas[] = ['modelo' => (string) $linha->getRawOriginal('modelo'), 'status' => (string) $linha->getRawOriginal('status'), 'quantidade' => (int) $linha->getAttribute('quantidade')];
            }
        }

        return $linhas;
    }

    /** @return list<array{mes: string, valor_centavos: int, quantidade: int}> */
    private function faturamento(string $ambiente, CarbonImmutable $inicioDoMes): array
    {
        $meses = [];
        for ($i = 11; $i >= 0; $i--) {
            $inicio = $inicioDoMes->subMonthsNoOverflow($i);
            [$de, $ate] = $this->limites($inicio);
            $base = Nota::query()->where('ambiente', $ambiente)->whereIn('status', self::STATUS_DE_VALOR)->whereBetween('emitido_em', [$de, $ate]);
            $meses[] = [
                'mes' => $inicio->format('Y-m'),
                'valor_centavos' => (int) (clone $base)->sum('total_centavos'),
                'quantidade' => (clone $base)->count(),
            ];
        }

        return $meses;
    }

    /** @return array{quantidade: int, recentes: list<array<string, mixed>>} */
    private function rejeicoes(string $ambiente): array
    {
        $base = Nota::query()->where('ambiente', $ambiente)->whereIn('status', [StatusNota::Rejeitada, StatusNota::Erro]);

        return [
            'quantidade' => (clone $base)->count(),
            'recentes' => (clone $base)->latest('created_at')->latest('id')->limit(5)->get()->map(fn (Nota $n): array => [
                'id' => $n->id, 'modelo' => $n->modelo->value, 'numero' => $n->numero, 'cstat' => $n->cstat, 'motivo' => $n->motivo,
                'mensagem_erro' => $n->mensagem_erro, 'status' => $n->status->value, 'created_at' => $n->created_at->toIso8601String(),
            ])->all(),
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Crm\Application\Relatorio;

use App\Modules\Crm\Domain\Contracts\DiretorioDeUsuarios;
use App\Modules\Crm\Domain\Enums\StatusDoNegocio;
use App\Modules\Crm\Domain\Enums\UnidadeDaMeta;
use App\Modules\Crm\Domain\Models\Etapa;
use App\Modules\Crm\Domain\Models\Funil;
use App\Modules\Crm\Domain\Models\MetaDoVendedor;
use App\Modules\Crm\Domain\Models\MotivoDePerda;
use App\Modules\Crm\Domain\Models\Negocio;
use App\Modules\Identity\Domain\Models\Usuario;
use Illuminate\Database\Eloquent\Builder;

/** Agregados de negócios e metas. Tudo em SQL (`GROUP BY`); respeita a visibilidade do vendedor. */
final class RelatorioDeNegocios
{
    public function __construct(private readonly DiretorioDeUsuarios $usuarios) {}

    /** @return Builder<Negocio> */
    public function negocios(Usuario $autor, ?string $vendedorId): Builder
    {
        $consulta = Negocio::query()->visiveisPara($autor);

        if ($vendedorId !== null) {
            $consulta->where('responsavel_id', $vendedorId);
        }

        return $consulta;
    }

    /**
     * @return array{
     *     abertos: array{quantidade: int, valor_centavos: int},
     *     ganhos: array{quantidade: int, valor_centavos: int},
     *     perdidos: array{quantidade: int, valor_centavos: int},
     *     taxa_de_conversao: float|null
     * }
     */
    public function resumo(Usuario $autor, PeriodoDoRelatorio $periodo, ?string $vendedorId): array
    {
        $fechados = fn (StatusDoNegocio $status): array => $this->totais(
            $this->negocios($autor, $vendedorId)->where('status', $status)
                ->whereBetween('fechado_em', [$periodo->inicioUtc(), $periodo->fimUtc()]),
        );

        $ganhos = $fechados(StatusDoNegocio::Ganho);
        $perdidos = $fechados(StatusDoNegocio::Perdido);
        $abertos = $this->totais($this->negocios($autor, $vendedorId)->where('status', StatusDoNegocio::Aberto));
        $fechadosNoPeriodo = $ganhos['quantidade'] + $perdidos['quantidade'];

        return [
            'abertos' => $abertos,
            'ganhos' => $ganhos,
            'perdidos' => $perdidos,
            'taxa_de_conversao' => $fechadosNoPeriodo === 0 ? null : round($ganhos['quantidade'] / $fechadosNoPeriodo, 4),
        ];
    }

    /** @return array{id: string, nome: string, etapas: list<array<string, mixed>>}|null */
    public function funil(Usuario $autor, ?string $vendedorId, ?string $funilId): ?array
    {
        $funil = $funilId !== null
            ? Funil::query()->findOrFail($funilId)
            : Funil::query()->where('padrao', true)->first();

        if ($funil === null) {
            return null;
        }

        $linhas = $this->negocios($autor, $vendedorId)
            ->where('status', StatusDoNegocio::Aberto)->where('funil_id', $funil->id)
            ->selectRaw('etapa_id, COUNT(*) AS quantidade, COALESCE(SUM(valor_centavos), 0) AS valor')
            ->groupBy('etapa_id')->get()->keyBy('etapa_id');

        $etapas = Etapa::query()->where('funil_id', $funil->id)->where('ativa', true)->orderBy('posicao')->get()
            ->map(fn (Etapa $e): array => [
                'id' => $e->id,
                'nome' => $e->nome,
                'probabilidade' => $e->probabilidade,
                'quantidade' => (int) $linhas->get($e->id)?->getAttribute('quantidade'),
                'valor_centavos' => (int) $linhas->get($e->id)?->getAttribute('valor'),
            ])->values()->all();

        return ['id' => $funil->id, 'nome' => $funil->nome, 'etapas' => $etapas];
    }

    /**
     * @return array{
     *     no_periodo: array{quantidade: int, valor_centavos: int, ponderada_centavos: int},
     *     sem_previsao: array{quantidade: int, valor_centavos: int}
     * }
     */
    public function previsao(Usuario $autor, PeriodoDoRelatorio $periodo, ?string $vendedorId): array
    {
        $linhas = $this->negocios($autor, $vendedorId)->where('status', StatusDoNegocio::Aberto)
            ->whereDate('previsao_fechamento', '>=', $periodo->inicioData())
            ->whereDate('previsao_fechamento', '<=', $periodo->fimData())
            ->selectRaw('etapa_id, COUNT(*) AS quantidade, COALESCE(SUM(valor_centavos), 0) AS valor')
            ->groupBy('etapa_id')->get();

        $probabilidades = Etapa::query()->whereIn('id', $linhas->pluck('etapa_id')->all())->pluck('probabilidade', 'id');

        $quantidade = 0;
        $valor = 0;
        $ponderado = 0;
        foreach ($linhas as $linha) {
            $quantidade += (int) $linha->getAttribute('quantidade');
            $valor += (int) $linha->getAttribute('valor');
            // A etapa existe (chave estrangeira): a probabilidade é sempre encontrada.
            $ponderado += (int) $linha->getAttribute('valor') * (int) $probabilidades[$linha->etapa_id];
        }

        return [
            'no_periodo' => ['quantidade' => $quantidade, 'valor_centavos' => $valor, 'ponderada_centavos' => intdiv($ponderado, 100)],
            'sem_previsao' => $this->totais(
                $this->negocios($autor, $vendedorId)->where('status', StatusDoNegocio::Aberto)->whereNull('previsao_fechamento'),
            ),
        ];
    }

    /** @return list<array{motivo_id: string|null, nome: string, quantidade: int, valor_centavos: int}> */
    public function motivosDePerda(Usuario $autor, PeriodoDoRelatorio $periodo, ?string $vendedorId): array
    {
        $linhas = $this->negocios($autor, $vendedorId)->where('status', StatusDoNegocio::Perdido)
            ->whereBetween('fechado_em', [$periodo->inicioUtc(), $periodo->fimUtc()])
            ->selectRaw('motivo_de_perda_id, COUNT(*) AS quantidade, COALESCE(SUM(valor_centavos), 0) AS valor')
            ->groupBy('motivo_de_perda_id')->get();

        $nomes = MotivoDePerda::query()->whereIn('id', $linhas->pluck('motivo_de_perda_id')->filter()->all())->pluck('nome', 'id');

        return $linhas->map(fn (Negocio $l): array => [
            'motivo_id' => $l->motivo_de_perda_id,
            'nome' => $l->motivo_de_perda_id === null ? 'Sem motivo' : (string) $nomes->get($l->motivo_de_perda_id, 'Motivo removido'),
            'quantidade' => (int) $l->getAttribute('quantidade'),
            'valor_centavos' => (int) $l->getAttribute('valor'),
        ])->sortByDesc('valor_centavos')->values()->all();
    }

    /**
     * Ranking por vendedor. Vendedor restrito não vê ranking: devolve `null`.
     *
     * @return list<array<string, mixed>>|null
     */
    public function vendedores(Usuario $autor, PeriodoDoRelatorio $periodo, ?string $vendedorId): ?array
    {
        if (Negocio::restritoParaVendedor($autor)) {
            return null;
        }

        $fechados = $this->negocios($autor, $vendedorId)->whereIn('status', [StatusDoNegocio::Ganho, StatusDoNegocio::Perdido])
            ->whereBetween('fechado_em', [$periodo->inicioUtc(), $periodo->fimUtc()])
            ->selectRaw('responsavel_id, status, COUNT(*) AS quantidade, COALESCE(SUM(valor_centavos), 0) AS valor')
            ->groupBy('responsavel_id', 'status')->get();
        $abertos = $this->negocios($autor, $vendedorId)->where('status', StatusDoNegocio::Aberto)
            ->selectRaw('responsavel_id, COUNT(*) AS quantidade, COALESCE(SUM(valor_centavos), 0) AS valor')
            ->groupBy('responsavel_id')->get();

        $metas = collect();
        if ($periodo->ehMesInteiro()) {
            $metas = MetaDoVendedor::query()->where('mes', $periodo->mes())
                ->when($vendedorId !== null, fn ($q) => $q->where('usuario_id', $vendedorId))
                ->get()->keyBy('usuario_id');
        }

        /** @var list<string> $ids */
        $ids = collect([...$fechados->pluck('responsavel_id'), ...$abertos->pluck('responsavel_id'), ...$metas->keys()])
            ->unique()->values()->all();
        $nomes = $this->usuarios->nomes($ids);
        $zero = ['quantidade' => 0, 'valor_centavos' => 0];

        $linhas = array_map(function (string $id) use ($fechados, $abertos, $metas, $nomes, $zero): array {
            $doVendedor = function (StatusDoNegocio $s) use ($fechados, $id, $zero): array {
                $l = $fechados->first(fn (Negocio $f): bool => $f->responsavel_id === $id && $f->status === $s);

                return $l === null
                    ? $zero
                    : ['quantidade' => (int) $l->getAttribute('quantidade'), 'valor_centavos' => (int) $l->getAttribute('valor')];
            };
            $abertosDele = $abertos->first(fn (Negocio $a): bool => $a->responsavel_id === $id);
            $ganhos = $doVendedor(StatusDoNegocio::Ganho);
            $meta = $metas->get($id);

            return [
                'usuario_id' => $id,
                'nome' => $nomes[$id] ?? 'Usuário removido',
                'abertos' => $abertosDele === null
                    ? $zero
                    : ['quantidade' => (int) $abertosDele->getAttribute('quantidade'), 'valor_centavos' => (int) $abertosDele->getAttribute('valor')],
                'ganhos' => $ganhos,
                'perdidos' => $doVendedor(StatusDoNegocio::Perdido),
                'meta' => $meta === null ? null : self::progresso($meta, $ganhos),
            ];
        }, $ids);

        usort($linhas, fn (array $a, array $b): int => $b['ganhos']['valor_centavos'] <=> $a['ganhos']['valor_centavos']);

        return $linhas;
    }

    /**
     * Meta do próprio usuário no mês do período, com o realizado. Só vale para mês inteiro.
     *
     * @return array{unidade: string, alvo: int, realizado: int, percentual: int}|null
     */
    public function metaDoUsuario(Usuario $usuario, PeriodoDoRelatorio $periodo): ?array
    {
        if (! $periodo->ehMesInteiro()) {
            return null;
        }

        $meta = MetaDoVendedor::query()->where('usuario_id', $usuario->id)->where('mes', $periodo->mes())->first();

        if ($meta === null) {
            return null;
        }

        $ganhos = $this->totais(
            Negocio::query()->where('responsavel_id', $usuario->id)->where('status', StatusDoNegocio::Ganho)
                ->whereBetween('fechado_em', [$periodo->inicioUtc(), $periodo->fimUtc()]),
        );

        return self::progresso($meta, $ganhos);
    }

    /**
     * @param  array{quantidade: int, valor_centavos: int}  $ganhos
     * @return array{unidade: string, alvo: int, realizado: int, percentual: int}
     */
    private static function progresso(MetaDoVendedor $meta, array $ganhos): array
    {
        $realizado = $meta->unidade === UnidadeDaMeta::Valor ? $ganhos['valor_centavos'] : $ganhos['quantidade'];

        // `alvo` é sempre maior que zero (validado em DefinirMeta).
        return [
            'unidade' => $meta->unidade->value,
            'alvo' => $meta->alvo,
            'realizado' => $realizado,
            'percentual' => intdiv($realizado * 100, $meta->alvo),
        ];
    }

    /**
     * @param  Builder<Negocio>  $consulta
     * @return array{quantidade: int, valor_centavos: int}
     */
    private function totais(Builder $consulta): array
    {
        $linha = $consulta->selectRaw('COUNT(*) AS quantidade, COALESCE(SUM(valor_centavos), 0) AS valor')->first();

        return [
            'quantidade' => (int) $linha?->getAttribute('quantidade'),
            'valor_centavos' => (int) $linha?->getAttribute('valor'),
        ];
    }
}

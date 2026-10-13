<?php

declare(strict_types=1);

namespace App\Modules\Crm\Application\Relatorio;

use App\Modules\Crm\Domain\Contracts\ConsultorDeValorFaturado;
use App\Modules\Crm\Domain\Enums\StatusDaProposta;
use App\Modules\Crm\Domain\Models\Proposta;
use App\Modules\Identity\Domain\Models\Usuario;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Agregados de propostas e do valor faturado a partir delas. A visibilidade vem dos negócios. */
final class RelatorioDePropostas
{
    public function __construct(
        private readonly RelatorioDeNegocios $negocios,
        private readonly ConsultorDeValorFaturado $faturamento,
    ) {}

    /** @return array{enviadas: int, aceitas: int, recusadas: int, valor_aceito_centavos: int, taxa_de_aceite: float|null} */
    public function propostas(Usuario $autor, PeriodoDoRelatorio $periodo, ?string $vendedorId): array
    {
        $entre = [$periodo->inicioUtc(), $periodo->fimUtc()];

        $enviadas = $this->visiveis($autor, $vendedorId)->whereBetween('enviada_em', $entre)->count();
        $recusadas = $this->visiveis($autor, $vendedorId)
            ->where('status', StatusDaProposta::Recusada)->whereBetween('respondida_em', $entre)->count();
        $aceitas = $this->aceitasNoPeriodo($autor, $periodo, $vendedorId);
        $respondidas = $aceitas->count() + $recusadas;

        return [
            'enviadas' => $enviadas,
            'aceitas' => $aceitas->count(),
            'recusadas' => $recusadas,
            'valor_aceito_centavos' => (int) $aceitas->sum(
                fn (Proposta $p): int => (int) $p->getAttribute('subtotal_centavos') - $p->desconto_centavos,
            ),
            'taxa_de_aceite' => $respondidas === 0 ? null : round($aceitas->count() / $respondidas, 4),
        ];
    }

    /** `null` quando o plano não inclui Fiscal; `0` quando inclui e nenhuma nota foi autorizada. */
    public function faturado(Usuario $autor, PeriodoDoRelatorio $periodo, ?string $vendedorId): ?int
    {
        if (! $this->faturamento->disponivel($autor)) {
            return null;
        }

        /** @var list<string> $ids */
        $ids = $this->aceitasNoPeriodo($autor, $periodo, $vendedorId)
            ->flatMap(fn (Proposta $p): array => $p->notas_ids ?? [])->values()->all();

        return $this->faturamento->totalAutorizadoCentavos($ids);
    }

    /** @return Builder<Proposta> */
    private function visiveis(Usuario $autor, ?string $vendedorId): Builder
    {
        return Proposta::query()->whereIn('negocio_id', $this->negocios->negocios($autor, $vendedorId)->select('id'));
    }

    /**
     * Linhas escalares: o subtotal vem de um `SUM` por proposta, sem hidratar itens.
     *
     * @return Collection<int, Proposta>
     */
    private function aceitasNoPeriodo(Usuario $autor, PeriodoDoRelatorio $periodo, ?string $vendedorId): Collection
    {
        return $this->visiveis($autor, $vendedorId)
            ->where('status', StatusDaProposta::Aceita)
            ->whereBetween('respondida_em', [$periodo->inicioUtc(), $periodo->fimUtc()])
            ->withSum('itens as subtotal_centavos', DB::raw('quantidade * valor_unitario_centavos'))
            ->get();
    }
}

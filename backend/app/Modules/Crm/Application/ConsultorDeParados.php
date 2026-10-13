<?php

declare(strict_types=1);

namespace App\Modules\Crm\Application;

use App\Modules\Crm\Domain\Enums\StatusDoNegocio;
use App\Modules\Crm\Domain\Enums\TipoDeRegistro;
use App\Modules\Crm\Domain\Models\Atividade;
use App\Modules\Crm\Domain\Models\Etapa;
use App\Modules\Crm\Domain\Models\Negocio;
use App\Modules\Crm\Domain\Models\RegistroDaTimeline;
use Carbon\Carbon;

/**
 * Parado = negócio aberto sem interação há `dias_para_parado` dias de calendário (fuso SP) ou mais.
 * Interação = criação, última movimentação de etapa ou última atividade concluída do negócio.
 */
final class ConsultorDeParados
{
    /**
     * @param  iterable<Negocio>  $negocios
     * @return array<string, array{parado: bool, dias: int}> só os negócios abertos, por id
     */
    public function situacao(iterable $negocios): array
    {
        $abertos = [];
        foreach ($negocios as $negocio) {
            if ($negocio->status === StatusDoNegocio::Aberto) {
                $abertos[$negocio->id] = $negocio;
            }
        }

        if ($abertos === []) {
            return [];
        }

        $ids = array_keys($abertos);
        $fuso = (string) config('app.fuso_negocio');

        /** @var array<string, string> $atividades */
        $atividades = Atividade::query()->whereIn('negocio_id', $ids)->whereNotNull('concluida_em')
            ->groupBy('negocio_id')->selectRaw('negocio_id, max(concluida_em) as ultima')
            ->pluck('ultima', 'negocio_id')->all();

        /** @var array<string, string> $movimentos */
        $movimentos = RegistroDaTimeline::query()->whereIn('negocio_id', $ids)
            ->where('tipo', TipoDeRegistro::NegocioMovido->value)
            ->groupBy('negocio_id')->selectRaw('negocio_id, max(ocorreu_em) as ultima')
            ->pluck('ultima', 'negocio_id')->all();

        $etapas = array_values(array_unique(array_map(fn (Negocio $n): string => $n->etapa_id, $abertos)));
        /** @var array<string, ?int> $limites */
        $limites = Etapa::query()->whereIn('id', $etapas)->pluck('dias_para_parado', 'id')->all();

        $hoje = now($fuso)->startOfDay();
        $situacao = [];

        foreach ($abertos as $id => $negocio) {
            $momentos = [$negocio->created_at->copy()];
            foreach ([$atividades[$id] ?? null, $movimentos[$id] ?? null] as $bruto) {
                if ($bruto !== null) {
                    // O banco guarda UTC; o agregado volta como texto.
                    $momentos[] = Carbon::parse($bruto, 'UTC');
                }
            }

            $ultima = collect($momentos)->max()->copy()->setTimezone($fuso)->startOfDay();
            $dias = (int) abs($ultima->diffInDays($hoje));
            $limite = $limites[$negocio->etapa_id] ?? null;

            $situacao[$id] = ['parado' => $limite !== null && $dias >= (int) $limite, 'dias' => $dias];
        }

        return $situacao;
    }
}

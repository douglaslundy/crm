<?php

declare(strict_types=1);

namespace App\Modules\Crm\Application\Actions;

use App\Modules\Crm\Application\PoliticaDoCrm;
use App\Modules\Crm\Domain\Enums\StatusDoNegocio;
use App\Modules\Crm\Domain\Events\NegocioMovido;
use App\Modules\Crm\Domain\Exceptions\RegraDoCrmException;
use App\Modules\Crm\Domain\Models\Etapa;
use App\Modules\Crm\Domain\Models\Negocio;
use App\Modules\Identity\Domain\Models\Usuario;
use Illuminate\Support\Facades\DB;

final class MoverNegocio
{
    public function __construct(private readonly PoliticaDoCrm $politica) {}

    public function executar(Usuario $autor, Negocio $negocio, string $etapaId, int $posicao): Negocio
    {
        $this->politica->garantirPodeOperarNegocios($autor);

        return DB::transaction(function () use ($autor, $negocio, $etapaId, $posicao): Negocio {
            // Serializa movimentos concorrentes do mesmo negócio.
            $negocio = Negocio::query()->lockForUpdate()->findOrFail($negocio->id);
            $negocio->garantirAberto();

            $destino = Etapa::query()
                ->where('funil_id', $negocio->funil_id)->where('ativa', true)->find($etapaId)
                ?? throw new RegraDoCrmException('Etapa não encontrada neste funil.', 'ETAPA_INVALIDA');

            $origemId = $negocio->etapa_id;

            // Trava as etapas envolvidas (em ordem de id, para não gerar deadlock) antes de ler as posições.
            Etapa::query()->whereIn('id', array_unique([$origemId, $destino->id]))->orderBy('id')->lockForUpdate()->get();

            $naEtapa = fn () => Negocio::query()
                ->where('etapa_id', $destino->id)->where('status', StatusDoNegocio::Aberto)->where('id', '!=', $negocio->id)
                ->orderBy('posicao_na_etapa')->orderBy('id');

            /** @var list<string> $ids todos os abertos da etapa, na ordem atual */
            $ids = $naEtapa()->pluck('id')->all();
            // A posição pedida é relativa ao que o autor enxerga: o cartão entra antes do cartão visível
            // que ocupa essa posição (ou no fim da coluna).
            $visiveis = array_flip($naEtapa()->visiveisPara($autor)->pluck('id')->all());
            $visiveisEmOrdem = array_values(array_filter($ids, fn (string $id): bool => isset($visiveis[$id])));
            $alvo = $visiveisEmOrdem[max(0, $posicao)] ?? null;
            $indice = $alvo === null ? count($ids) : (int) array_search($alvo, $ids, true);
            array_splice($ids, $indice, 0, [$negocio->id]);

            $negocio->update(['etapa_id' => $destino->id]);
            $this->reindexar($ids);

            if ($origemId !== $destino->id) {
                /** @var list<string> $restantes */
                $restantes = Negocio::query()
                    ->where('etapa_id', $origemId)->where('status', StatusDoNegocio::Aberto)
                    ->orderBy('posicao_na_etapa')->orderBy('id')->pluck('id')->all();
                $this->reindexar($restantes);

                DB::afterCommit(fn () => event(new NegocioMovido(
                    $autor->tenant_id ?? '', $negocio->id, $negocio->cliente_id, $autor->id, $origemId, $destino->id,
                )));
            }

            return $negocio->refresh()->load('itens');
        });
    }

    /** @param list<string> $ids */
    private function reindexar(array $ids): void
    {
        foreach ($ids as $posicao => $id) {
            Negocio::query()->whereKey($id)->update(['posicao_na_etapa' => $posicao]);
        }
    }
}

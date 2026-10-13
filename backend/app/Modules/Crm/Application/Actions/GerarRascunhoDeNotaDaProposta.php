<?php

declare(strict_types=1);

namespace App\Modules\Crm\Application\Actions;

use App\Modules\Crm\Application\PoliticaDoCrm;
use App\Modules\Crm\Application\RegistradorDaTimeline;
use App\Modules\Crm\Domain\Contracts\GeradorDeRascunhoDeNota;
use App\Modules\Crm\Domain\Enums\StatusDaProposta;
use App\Modules\Crm\Domain\Enums\TipoDeRegistro;
use App\Modules\Crm\Domain\Exceptions\RegraDoCrmException;
use App\Modules\Crm\Domain\LinhaDeProposta;
use App\Modules\Crm\Domain\Models\ItemDaProposta;
use App\Modules\Crm\Domain\Models\Proposta;
use App\Modules\Identity\Domain\Models\Usuario;
use Illuminate\Support\Facades\DB;

final class GerarRascunhoDeNotaDaProposta
{
    public function __construct(
        private readonly PoliticaDoCrm $politica,
        private readonly GeradorDeRascunhoDeNota $gerador,
        private readonly RegistradorDaTimeline $timeline,
    ) {}

    /** @return list<string> ids dos rascunhos (um por tipo de item) */
    public function executar(Usuario $autor, Proposta $proposta): array
    {
        $this->politica->garantirPodeOperarNegocios($autor);

        return DB::transaction(function () use ($autor, $proposta): array {
            $proposta = Proposta::query()->with('itens')->lockForUpdate()->findOrFail($proposta->id);
            if ($proposta->status !== StatusDaProposta::Aceita) {
                throw new RegraDoCrmException('Só proposta aceita gera rascunho de nota.', 'PROPOSTA_NAO_ACEITA');
            }
            if ($proposta->notas_ids !== null && $proposta->notas_ids !== []) {
                return $proposta->notas_ids;   // idempotente
            }
            if (! $this->gerador->disponivel($autor)) {
                throw new RegraDoCrmException('O plano da empresa não inclui emissão fiscal. Troque de plano para emitir a nota.', 'FISCAL_INDISPONIVEL');
            }

            $ids = $this->gerador->gerar(
                $autor,
                $proposta->cliente_id,
                $proposta->itens->map(fn (ItemDaProposta $i): LinhaDeProposta => new LinhaDeProposta(
                    $i->tipo, $i->item_id, $i->quantidade, $i->valor_unitario_centavos,
                ))->values()->all(),
                $proposta->desconto_centavos,
            );

            $proposta->forceFill(['notas_ids' => $ids])->save();
            $this->timeline->registrar(
                TipoDeRegistro::PropostaNotaGerada, $proposta->cliente_id, $proposta->negocio_id, $autor->id,
                "Rascunho de nota gerado a partir da proposta #{$proposta->numero}",
                ['proposta_id' => $proposta->id, 'numero' => $proposta->numero, 'notas_ids' => $ids],
            );

            return $ids;
        });
    }
}

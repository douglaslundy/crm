<?php

declare(strict_types=1);

namespace App\Modules\Crm\Application\Actions;

use App\Modules\Crm\Application\PoliticaDoCrm;
use App\Modules\Crm\Domain\Enums\StatusDoNegocio;
use App\Modules\Crm\Domain\Events\NegocioPerdido;
use App\Modules\Crm\Domain\Exceptions\RegraDoCrmException;
use App\Modules\Crm\Domain\Models\MotivoDePerda;
use App\Modules\Crm\Domain\Models\Negocio;
use App\Modules\Identity\Domain\Models\Usuario;
use Illuminate\Support\Facades\DB;

final class PerderNegocio
{
    public function __construct(private readonly PoliticaDoCrm $politica) {}

    public function executar(Usuario $autor, Negocio $negocio, string $motivoId, ?string $observacao): Negocio
    {
        $this->politica->garantirPodeOperarNegocios($autor);

        return DB::transaction(function () use ($autor, $negocio, $motivoId, $observacao): Negocio {
            $negocio = Negocio::query()->lockForUpdate()->findOrFail($negocio->id);
            $negocio->garantirAberto();

            $motivo = MotivoDePerda::query()->where('ativo', true)->find($motivoId)
                ?? throw new RegraDoCrmException('Escolha um motivo de perda válido.', 'MOTIVO_INVALIDO');

            $negocio->update([
                'status' => StatusDoNegocio::Perdido,
                'motivo_de_perda_id' => $motivo->id,
                'observacao_da_perda' => $observacao === null || trim($observacao) === '' ? null : trim($observacao),
                'fechado_em' => now(),
            ]);

            DB::afterCommit(fn () => event(new NegocioPerdido(
                $autor->tenant_id ?? '', $negocio->id, $negocio->cliente_id, $autor->id, $motivo->id,
            )));

            return $negocio->refresh()->load('itens');
        });
    }
}

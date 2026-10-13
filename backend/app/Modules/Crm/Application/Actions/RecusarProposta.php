<?php

declare(strict_types=1);

namespace App\Modules\Crm\Application\Actions;

use App\Modules\Crm\Application\RegistradorDaTimeline;
use App\Modules\Crm\Application\ResolvedorDePropostaPublica;
use App\Modules\Crm\Application\ValidadorDeProposta;
use App\Modules\Crm\Domain\Enums\StatusDaProposta;
use App\Modules\Crm\Domain\Enums\TipoDeRegistro;
use App\Modules\Crm\Domain\Models\Proposta;
use Illuminate\Support\Facades\DB;

final class RecusarProposta
{
    public function __construct(
        private readonly ResolvedorDePropostaPublica $resolvedor,
        private readonly RegistradorDaTimeline $timeline,
    ) {}

    public function executar(string $token, ?string $motivo, ?string $ip): Proposta
    {
        $resolvida = $this->resolvedor->resolver($token);
        $motivo = $motivo === null ? null : trim($motivo);
        $motivo = $motivo === '' ? null : $motivo;

        return DB::transaction(function () use ($resolvida, $motivo): Proposta {
            $proposta = Proposta::query()->lockForUpdate()->findOrFail($resolvida->id);
            if ($proposta->status === StatusDaProposta::Recusada) {
                return $proposta->load('itens');   // idempotente
            }
            ValidadorDeProposta::garantirRespondivel($proposta);

            $proposta->forceFill([
                'status' => StatusDaProposta::Recusada, 'motivo_recusa' => $motivo, 'respondida_em' => now(),
            ])->save();

            $this->timeline->registrar(
                TipoDeRegistro::PropostaRecusada, $proposta->cliente_id, $proposta->negocio_id, null,
                "Proposta #{$proposta->numero} recusada".($motivo === null ? '' : ": {$motivo}"),
                ['proposta_id' => $proposta->id, 'numero' => $proposta->numero, 'motivo' => $motivo],
            );

            return $proposta->refresh()->load('itens');
        });
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Crm\Application\Actions;

use App\Modules\Crm\Application\FecharNegocioComoGanho;
use App\Modules\Crm\Application\RegistradorDaTimeline;
use App\Modules\Crm\Application\ResolvedorDePropostaPublica;
use App\Modules\Crm\Application\ValidadorDeProposta;
use App\Modules\Crm\Domain\Enums\StatusDaProposta;
use App\Modules\Crm\Domain\Enums\TipoDeRegistro;
use App\Modules\Crm\Domain\Exceptions\RegraDoCrmException;
use App\Modules\Crm\Domain\Models\Negocio;
use App\Modules\Crm\Domain\Models\Proposta;
use Illuminate\Support\Facades\DB;

/** Rota pública: sem usuário e sem checagem de módulo do plano (o cliente do cliente não deve ver erro de plano). */
final class AceitarProposta
{
    public function __construct(
        private readonly ResolvedorDePropostaPublica $resolvedor,
        private readonly FecharNegocioComoGanho $fechar,
        private readonly RegistradorDaTimeline $timeline,
    ) {}

    public function executar(string $token, string $nome, ?string $ip): Proposta
    {
        $resolvida = $this->resolvedor->resolver($token);
        $nome = trim($nome);
        if ($nome === '') {
            throw new RegraDoCrmException('Informe seu nome para aceitar.', 'ACEITE_SEM_NOME');
        }

        return DB::transaction(function () use ($resolvida, $nome, $ip): Proposta {
            $proposta = Proposta::query()->lockForUpdate()->findOrFail($resolvida->id);
            if ($proposta->status === StatusDaProposta::Aceita) {
                return $proposta->load('itens');   // idempotente
            }
            ValidadorDeProposta::garantirRespondivel($proposta);

            $negocio = Negocio::query()->findOrFail($proposta->negocio_id);
            $proposta->forceFill([
                'status' => StatusDaProposta::Aceita, 'aceite_nome' => $nome, 'aceite_ip' => $ip, 'respondida_em' => now(),
            ])->save();

            // Lança NEGOCIO_FECHADO se a equipe já fechou o negócio, e a transação desfaz o aceite.
            $this->fechar->executar($negocio, null);

            Proposta::query()
                ->where('negocio_id', $negocio->id)
                ->where('id', '!=', $proposta->id)
                ->whereIn('status', [StatusDaProposta::Rascunho->value, StatusDaProposta::Enviada->value])
                ->get()
                ->each(fn (Proposta $outra) => $outra->forceFill([
                    'status' => StatusDaProposta::Cancelada, 'token_hash' => null, 'respondida_em' => now(),
                ])->save());

            $this->timeline->registrar(
                TipoDeRegistro::PropostaAceita, $proposta->cliente_id, $negocio->id, null,
                "Proposta #{$proposta->numero} aceita por {$nome}",
                ['proposta_id' => $proposta->id, 'numero' => $proposta->numero, 'aceite_nome' => $nome],
            );

            return $proposta->refresh()->load('itens');
        });
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Crm\Application\Actions;

use App\Modules\Crm\Application\PoliticaDoCrm;
use App\Modules\Crm\Application\RegistradorDaTimeline;
use App\Modules\Crm\Application\ValidadorDeProposta;
use App\Modules\Crm\Domain\Enums\StatusDaProposta;
use App\Modules\Crm\Domain\Enums\TipoDeRegistro;
use App\Modules\Crm\Domain\Models\Negocio;
use App\Modules\Crm\Domain\Models\Proposta;
use App\Modules\Identity\Domain\Models\Usuario;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class EnviarProposta
{
    public function __construct(
        private readonly PoliticaDoCrm $politica,
        private readonly RegistradorDaTimeline $timeline,
    ) {}

    public function executar(Usuario $autor, Proposta $proposta): Proposta
    {
        $this->politica->garantirPodeOperarNegocios($autor);

        return DB::transaction(function () use ($autor, $proposta): Proposta {
            $proposta = Proposta::query()->lockForUpdate()->findOrFail($proposta->id);
            ValidadorDeProposta::garantirEditavel($proposta);
            Negocio::query()->findOrFail($proposta->negocio_id)->garantirAberto();
            ValidadorDeProposta::garantirDescontoValido($proposta);

            $token = Str::random(64);
            $proposta->forceFill([
                'status' => StatusDaProposta::Enviada,
                'token' => $token,
                'token_hash' => hash('sha256', $token),
                'enviada_em' => now(),
                'validade_em' => now()->addDays($proposta->validade_dias),
            ])->save();

            $total = $proposta->totalCentavos();
            $this->timeline->registrar(
                TipoDeRegistro::PropostaEnviada, $proposta->cliente_id, $proposta->negocio_id, $autor->id,
                "Proposta #{$proposta->numero} enviada (R$ ".number_format($total / 100, 2, ',', '.').')',
                ['proposta_id' => $proposta->id, 'numero' => $proposta->numero, 'total_centavos' => $total],
            );

            return $proposta->refresh()->load('itens');
        });
    }
}

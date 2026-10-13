<?php

declare(strict_types=1);

namespace App\Modules\Crm\Application\Actions;

use App\Modules\Crm\Application\PoliticaDoCrm;
use App\Modules\Crm\Application\RegistradorDaTimeline;
use App\Modules\Crm\Domain\Enums\TipoDeRegistro;
use App\Modules\Crm\Domain\Models\Atividade;
use App\Modules\Identity\Domain\Models\Usuario;
use Illuminate\Support\Facades\DB;

final class ConcluirAtividade
{
    public function __construct(
        private readonly PoliticaDoCrm $politica,
        private readonly RegistradorDaTimeline $timeline,
    ) {}

    public function executar(Usuario $autor, Atividade $atividade): Atividade
    {
        $this->politica->garantirPodeOperarNegocios($autor);

        return DB::transaction(function () use ($autor, $atividade): Atividade {
            $atividade = Atividade::query()->lockForUpdate()->findOrFail($atividade->id);

            if ($atividade->estaConcluida()) {
                return $atividade;
            }

            $agora = now();
            $atividade->update(['concluida_em' => $agora]);

            $this->timeline->registrar(
                TipoDeRegistro::AtividadeConcluida,
                $atividade->cliente_id,
                $atividade->negocio_id,
                $autor->id,
                $atividade->titulo,
                ['tipo' => $atividade->tipo->value, 'titulo' => $atividade->titulo],
                $atividade->id,
                $agora,
            );

            return $atividade->refresh();
        });
    }
}

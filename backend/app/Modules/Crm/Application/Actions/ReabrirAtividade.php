<?php

declare(strict_types=1);

namespace App\Modules\Crm\Application\Actions;

use App\Modules\Crm\Application\PoliticaDoCrm;
use App\Modules\Crm\Domain\Enums\TipoDeRegistro;
use App\Modules\Crm\Domain\Models\Atividade;
use App\Modules\Crm\Domain\Models\RegistroDaTimeline;
use App\Modules\Identity\Domain\Models\Usuario;
use Illuminate\Support\Facades\DB;

final class ReabrirAtividade
{
    public function __construct(private readonly PoliticaDoCrm $politica) {}

    public function executar(Usuario $autor, Atividade $atividade): Atividade
    {
        $this->politica->garantirPodeOperarNegocios($autor);

        return DB::transaction(function () use ($atividade): Atividade {
            $atividade = Atividade::query()->lockForUpdate()->findOrFail($atividade->id);

            if ($atividade->estaConcluida()) {
                $atividade->update(['concluida_em' => null]);
            }

            // A timeline não conta uma conclusão desfeita.
            RegistroDaTimeline::query()
                ->where('atividade_id', $atividade->id)
                ->where('tipo', TipoDeRegistro::AtividadeConcluida)
                ->delete();

            return $atividade->refresh();
        });
    }
}

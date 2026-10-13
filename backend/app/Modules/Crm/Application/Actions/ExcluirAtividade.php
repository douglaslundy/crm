<?php

declare(strict_types=1);

namespace App\Modules\Crm\Application\Actions;

use App\Modules\Crm\Application\PoliticaDoCrm;
use App\Modules\Crm\Domain\Models\Atividade;
use App\Modules\Identity\Domain\Models\Usuario;

final class ExcluirAtividade
{
    public function __construct(private readonly PoliticaDoCrm $politica) {}

    /** Registros já gravados na timeline ficam: a história não some com a atividade. */
    public function executar(Usuario $autor, Atividade $atividade): void
    {
        $this->politica->garantirPodeOperarNegocios($autor);

        $atividade->delete();
    }
}

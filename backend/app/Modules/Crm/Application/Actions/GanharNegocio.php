<?php

declare(strict_types=1);

namespace App\Modules\Crm\Application\Actions;

use App\Modules\Crm\Application\FecharNegocioComoGanho;
use App\Modules\Crm\Application\PoliticaDoCrm;
use App\Modules\Crm\Domain\Models\Negocio;
use App\Modules\Identity\Domain\Models\Usuario;
use Illuminate\Support\Facades\DB;

final class GanharNegocio
{
    public function __construct(
        private readonly PoliticaDoCrm $politica,
        private readonly FecharNegocioComoGanho $fechar,
    ) {}

    public function executar(Usuario $autor, Negocio $negocio): Negocio
    {
        $this->politica->garantirPodeOperarNegocios($autor);

        return DB::transaction(fn (): Negocio => $this->fechar->executar($negocio, $autor->id));
    }
}

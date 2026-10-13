<?php

declare(strict_types=1);

namespace App\Modules\Crm\Application\Actions;

use App\Modules\Crm\Application\PoliticaDoCrm;
use App\Modules\Crm\Domain\Contracts\ConsultaDeClientes;
use App\Modules\Crm\Domain\Enums\StatusDoNegocio;
use App\Modules\Crm\Domain\Events\NegocioGanho;
use App\Modules\Crm\Domain\Models\Negocio;
use App\Modules\Identity\Domain\Models\Usuario;
use Illuminate\Support\Facades\DB;

final class GanharNegocio
{
    public function __construct(
        private readonly PoliticaDoCrm $politica,
        private readonly ConsultaDeClientes $clientes,
    ) {}

    public function executar(Usuario $autor, Negocio $negocio): Negocio
    {
        $this->politica->garantirPodeOperarNegocios($autor);

        return DB::transaction(function () use ($autor, $negocio): Negocio {
            $negocio = Negocio::query()->lockForUpdate()->findOrFail($negocio->id);
            $negocio->garantirAberto();

            $negocio->update(['status' => StatusDoNegocio::Ganho, 'fechado_em' => now()]);

            if ($this->clientes->resumo($negocio->cliente_id)?->ehLead === true) {
                $this->clientes->converterEmCliente($negocio->cliente_id);
            }

            DB::afterCommit(fn () => event(new NegocioGanho($autor->tenant_id ?? '', $negocio->id, $negocio->cliente_id, $autor->id)));

            return $negocio->refresh()->load('itens');
        });
    }
}

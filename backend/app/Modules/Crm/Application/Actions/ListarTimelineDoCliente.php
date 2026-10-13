<?php

declare(strict_types=1);

namespace App\Modules\Crm\Application\Actions;

use App\Modules\Crm\Application\PoliticaDoCrm;
use App\Modules\Crm\Domain\Contracts\ConsultaDeClientes;
use App\Modules\Crm\Domain\Models\RegistroDaTimeline;
use App\Modules\Identity\Domain\Models\Usuario;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Database\Eloquent\ModelNotFoundException;

final class ListarTimelineDoCliente
{
    public const POR_PAGINA = 30;

    public function __construct(
        private readonly PoliticaDoCrm $politica,
        private readonly ConsultaDeClientes $clientes,
    ) {}

    /** @return CursorPaginator<int, RegistroDaTimeline> */
    public function executar(Usuario $autor, string $clienteId): CursorPaginator
    {
        $this->politica->garantirPodeLer($autor);

        if ($this->clientes->resumo($clienteId) === null) {
            throw new ModelNotFoundException;
        }

        return RegistroDaTimeline::query()->visiveisPara($autor)
            ->where('cliente_id', $clienteId)
            ->orderByDesc('ocorreu_em')->orderByDesc('id')
            ->cursorPaginate(self::POR_PAGINA);
    }
}

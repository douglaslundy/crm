<?php

declare(strict_types=1);

namespace App\Modules\Crm\Application\Actions;

use App\Modules\Crm\Application\PoliticaDoCrm;
use App\Modules\Crm\Domain\Models\MetaDoVendedor;
use App\Modules\Identity\Domain\Models\Usuario;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

final class ListarMetas
{
    public function __construct(private readonly PoliticaDoCrm $politica) {}

    /** @return Collection<int, MetaDoVendedor> */
    public function executar(Usuario $autor, CarbonInterface $mes): Collection
    {
        $this->politica->garantirPodeLer($autor);

        $consulta = MetaDoVendedor::query()->where('mes', $mes->copy()->startOfMonth()->toDateString());

        if (! $this->politica->podeReatribuir($autor)) {
            $consulta->where('usuario_id', $autor->id);
        }

        return $consulta->orderBy('id')->get();
    }
}

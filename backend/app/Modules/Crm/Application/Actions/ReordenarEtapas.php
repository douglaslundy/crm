<?php

declare(strict_types=1);

namespace App\Modules\Crm\Application\Actions;

use App\Modules\Crm\Application\PoliticaDoCrm;
use App\Modules\Crm\Domain\Exceptions\RegraDoCrmException;
use App\Modules\Crm\Domain\Models\Etapa;
use App\Modules\Crm\Domain\Models\Funil;
use App\Modules\Identity\Domain\Models\Usuario;
use Illuminate\Support\Facades\DB;

final class ReordenarEtapas
{
    public function __construct(private readonly PoliticaDoCrm $politica) {}

    /** @param list<string> $idsEmOrdem todas as etapas do funil, na nova ordem */
    public function executar(Usuario $autor, Funil $funil, array $idsEmOrdem): void
    {
        $this->politica->garantirPodeConfigurar($autor);

        /** @var list<string> $atuais */
        $atuais = Etapa::query()->where('funil_id', $funil->id)->pluck('id')->all();
        $pedidos = array_values(array_unique($idsEmOrdem));
        sort($atuais);
        $ordenados = $pedidos;
        sort($ordenados);

        if ($ordenados !== $atuais || count($pedidos) !== count($idsEmOrdem)) {
            throw new RegraDoCrmException('Informe todas as etapas do funil, sem repetir.', 'ORDEM_INVALIDA');
        }

        DB::transaction(function () use ($pedidos): void {
            foreach ($pedidos as $posicao => $id) {
                Etapa::query()->whereKey($id)->update(['posicao' => $posicao]);
            }
        });
    }
}

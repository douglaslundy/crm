<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Application\Actions;

use App\Modules\Fiscal\Application\PoliticaDeNotas;
use App\Modules\Fiscal\Application\Vendas\GravadorDaVenda;
use App\Modules\Fiscal\Domain\Models\Venda;
use App\Modules\Identity\Domain\Models\Usuario;
use Illuminate\Support\Facades\DB;

final class ExcluirVendaMista
{
    public function __construct(
        private readonly PoliticaDeNotas $politica,
        private readonly GravadorDaVenda $gravador,
        private readonly ExcluirRascunhoDeNota $exclusao,
    ) {}

    public function executar(Venda $venda, Usuario $autor): void
    {
        $this->politica->garantirPodeRascunhar($autor);

        DB::transaction(function () use ($venda): void {
            $venda = Venda::query()->whereKey($venda->id)->lockForUpdate()->firstOrFail();
            foreach ($this->gravador->notasEditaveis($venda, true) as $nota) {
                $this->exclusao->executar($nota, true);
            }
            $venda->delete();
        });
    }
}

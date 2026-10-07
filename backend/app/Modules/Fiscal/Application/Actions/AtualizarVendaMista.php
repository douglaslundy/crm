<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Application\Actions;

use App\Modules\Fiscal\Application\PoliticaDeNotas;
use App\Modules\Fiscal\Application\Vendas\GravadorDaVenda;
use App\Modules\Fiscal\Domain\Models\Venda;
use App\Modules\Identity\Domain\Models\Usuario;
use Illuminate\Support\Facades\DB;

/** Refaz itens e rateio das duas notas; só enquanto as duas estão em rascunho. */
final class AtualizarVendaMista
{
    public function __construct(private readonly PoliticaDeNotas $politica, private readonly GravadorDaVenda $gravador) {}

    /** @param array<string, mixed> $dados */
    public function executar(Venda $venda, array $dados, Usuario $autor): Venda
    {
        $this->politica->garantirPodeRascunhar($autor);
        GravadorDaVenda::exigirOsDoisTipos($dados);

        return DB::transaction(function () use ($venda, $dados): Venda {
            $venda = Venda::query()->whereKey($venda->id)->lockForUpdate()->firstOrFail();
            [$produtos, $servicos] = $this->gravador->notasEditaveis($venda);

            $totais = $this->gravador->gravar($produtos, $servicos, $dados);
            $venda->forceFill([
                'cliente_id' => $dados['cliente_id'],
                'desconto_centavos' => $totais['desconto'], 'subtotal_centavos' => $totais['subtotal'], 'total_centavos' => $totais['total'],
            ])->save();

            return $venda->refresh();
        });
    }
}

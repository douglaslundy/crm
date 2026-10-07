<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Application\Actions;

use App\Modules\Fiscal\Application\PoliticaDeNotas;
use App\Modules\Fiscal\Application\Vendas\GravadorDaVenda;
use App\Modules\Fiscal\Domain\Models\Venda;
use App\Modules\Identity\Domain\Models\Usuario;
use App\Modules\Platform\Application\EntitlementService;
use App\Modules\Platform\Domain\Enums\Modulo;
use Illuminate\Support\Facades\DB;

/** Venda de produtos e serviços: gera os rascunhos de uma NF-e e de uma NFS-e independentes, com o desconto rateado. */
final class CriarVendaMista
{
    public function __construct(
        private readonly PoliticaDeNotas $politica,
        private readonly EntitlementService $planos,
        private readonly CriarRascunhoDeNota $rascunhos,
        private readonly GravadorDaVenda $gravador,
    ) {}

    /** @param array<string, mixed> $dados */
    public function executar(array $dados, Usuario $autor): Venda
    {
        $this->politica->garantirPodeRascunhar($autor);
        GravadorDaVenda::exigirOsDoisTipos($dados);
        $this->planos->garantirModulo($autor->tenant, Modulo::FiscalNfe);
        $this->planos->garantirModulo($autor->tenant, Modulo::FiscalNfse);

        return DB::transaction(function () use ($dados, $autor): Venda {
            $venda = new Venda;
            $venda->fill(['cliente_id' => $dados['cliente_id']]);
            $venda->forceFill(['criada_por' => $autor->id])->save();

            $base = ['cliente_id' => $dados['cliente_id'], 'desconto_centavos' => 0];
            $produtos = $this->rascunhos->executar($base + ['modelo' => 'NFE', 'itens' => $dados['itens_produtos'], 'pagamentos' => $dados['pagamentos']], $autor, $venda->id);
            $servicos = $this->rascunhos->executar($base + ['modelo' => 'DPS', 'itens' => $dados['itens_servicos']], $autor, $venda->id);

            $totais = $this->gravador->gravar($produtos, $servicos, $dados);
            $venda->forceFill([
                'desconto_centavos' => $totais['desconto'], 'subtotal_centavos' => $totais['subtotal'], 'total_centavos' => $totais['total'],
            ])->save();

            return $venda->refresh();
        });
    }
}

<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use App\Modules\Platform\Domain\Enums\Modulo;

/** Cenário da NF-e e da NFS-e (módulos, séries, produto, serviço e cliente). */
trait CenarioDeVenda
{
    use CenarioDeNfse;

    protected function montarCenarioVenda(): void
    {
        $this->montarCenarioNfse(Modulo::FiscalNfe);
    }

    /**
     * Produto R$ 10,00 + serviço R$ 150,00. Com desconto 0 a NF-e fecha em 1000 e a NFS-e em 15000.
     *
     * @param  array<string, mixed>  $sobrescrever
     * @return array<string, mixed>
     */
    protected function corpoVenda(array $sobrescrever = []): array
    {
        return array_replace([
            'cliente_id' => $this->cliente->id, 'desconto_centavos' => 0, 'informacoes_complementares' => null,
            'itens_produtos' => [['produto_id' => $this->produto->id, 'quantidade' => '1', 'valor_unitario_centavos' => null]],
            'itens_servicos' => [['servico_id' => $this->servico->id, 'quantidade' => '1', 'valor_unitario_centavos' => null]],
            'pagamentos' => [['tpag' => '01', 'xpag' => null, 'valor_centavos' => 1000]],
        ], $sobrescrever);
    }
}

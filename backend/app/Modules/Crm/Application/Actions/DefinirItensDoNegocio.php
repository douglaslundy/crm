<?php

declare(strict_types=1);

namespace App\Modules\Crm\Application\Actions;

use App\Modules\Crm\Application\PoliticaDoCrm;
use App\Modules\Crm\Domain\Contracts\CatalogoDeItens;
use App\Modules\Crm\Domain\Enums\TipoDeItem;
use App\Modules\Crm\Domain\Exceptions\RegraDoCrmException;
use App\Modules\Crm\Domain\Models\Negocio;
use App\Modules\Identity\Domain\Models\Usuario;
use Illuminate\Support\Facades\DB;

final class DefinirItensDoNegocio
{
    public function __construct(
        private readonly PoliticaDoCrm $politica,
        private readonly CatalogoDeItens $catalogo,
    ) {}

    /** @param list<array{tipo: string, item_id: string, quantidade: int}> $itens */
    public function executar(Usuario $autor, Negocio $negocio, array $itens): Negocio
    {
        $this->politica->garantirPodeOperarNegocios($autor);

        return DB::transaction(function () use ($negocio, $itens): Negocio {
            // Relê travado: o negócio pode ter fechado ou mudado desde que o controller o carregou.
            $negocio = Negocio::query()->lockForUpdate()->findOrFail($negocio->id);
            $negocio->garantirAberto();
            $this->aplicar($negocio, $itens);

            return $negocio->refresh()->load('itens');
        });
    }

    /**
     * Sem autorização: quem chama já autorizou. Substitui os itens com snapshot do catálogo e,
     * havendo itens, o valor do negócio passa a ser a soma deles.
     *
     * @param  list<array{tipo: string, item_id: string, quantidade: int}>  $itens
     */
    public function aplicar(Negocio $negocio, array $itens): void
    {
        $linhas = [];
        foreach ($itens as $item) {
            $descrito = $this->catalogo->descrever(TipoDeItem::from($item['tipo']), $item['item_id']);
            if ($descrito === null) {
                throw new RegraDoCrmException('Item não encontrado no catálogo.', 'ITEM_NAO_ENCONTRADO');
            }

            $linhas[] = [
                'tipo' => $descrito->tipo,
                'item_id' => $descrito->itemId,
                'descricao' => $descrito->descricao,
                'quantidade' => $item['quantidade'],
                'valor_unitario_centavos' => $descrito->valorUnitarioCentavos,
            ];
        }

        $negocio->itens()->delete();
        foreach ($linhas as $linha) {
            $negocio->itens()->create($linha);
        }

        if ($linhas !== []) {
            $negocio->update(['valor_centavos' => array_sum(array_map(
                fn (array $l): int => $l['quantidade'] * $l['valor_unitario_centavos'],
                $linhas,
            ))]);
        }
    }
}

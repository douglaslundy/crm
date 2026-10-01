<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Resources;

use App\Modules\Catalog\Domain\Models\Produto;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Produto */
final class ProdutoResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'sku' => $this->sku,
            'nome' => $this->nome,
            'unidade' => $this->unidade,
            'preco_centavos' => $this->preco_centavos,
            'gtin' => $this->gtin,
            'ncm' => $this->ncm,
            'cest' => $this->cest,
            'origem' => $this->origem,
            'tributacao_icms' => $this->tributacao_icms?->value,
            'fiscal_fonte' => $this->fiscal_fonte->value,
            'fiscal_revisado_em' => $this->fiscal_revisado_em?->toIso8601String(),
            'pendente_fiscal' => $this->pendenteDeRevisaoFiscal(),
        ];
    }
}

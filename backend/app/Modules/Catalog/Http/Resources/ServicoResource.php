<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Resources;

use App\Modules\Catalog\Domain\Models\Servico;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Servico */
final class ServicoResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'nome' => $this->nome,
            'preco_centavos' => $this->preco_centavos,
            'codigo_lc116' => $this->codigo_lc116,
            'c_trib_nac' => $this->c_trib_nac,
            'codigo_municipal' => $this->codigo_municipal,
            'aliquota_iss' => $this->aliquota_iss,
            'nbs' => $this->nbs,
            'pendente_fiscal' => $this->pendenteDeRevisaoFiscal(),
        ];
    }
}

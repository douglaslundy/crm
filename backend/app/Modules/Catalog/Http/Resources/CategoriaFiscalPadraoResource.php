<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Resources;

use App\Modules\Catalog\Domain\Models\CategoriaFiscalPadrao;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin CategoriaFiscalPadrao */
final class CategoriaFiscalPadraoResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'categoria' => $this->categoria,
            'ncm' => $this->ncm,
            'origem' => $this->origem,
            'tributacao_icms' => $this->tributacao_icms?->value,
        ];
    }
}

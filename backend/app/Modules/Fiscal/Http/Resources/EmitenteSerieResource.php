<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Http\Resources;

use App\Modules\Fiscal\Domain\Models\EmitenteSerie;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin EmitenteSerie */
final class EmitenteSerieResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'modelo' => $this->modelo->value,
            'serie' => $this->serie,
            'proximo_numero' => $this->proximo_numero,
        ];
    }
}

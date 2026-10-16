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
            // O contador do ambiente em que o emitente opera agora.
            'proximo_numero' => $this->proximoNumeroDo($this->emitente->ambiente_fiscal),
        ];
    }
}

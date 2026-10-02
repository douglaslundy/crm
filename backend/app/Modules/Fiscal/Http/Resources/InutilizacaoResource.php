<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Http\Resources;

use App\Modules\Fiscal\Domain\Models\Inutilizacao;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Inutilizacao */
final class InutilizacaoResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id, 'serie' => $this->serie, 'numero_inicial' => $this->numero_inicial, 'numero_final' => $this->numero_final,
            'justificativa' => $this->justificativa, 'ambiente' => $this->ambiente, 'status' => $this->status->value,
            'cstat' => $this->cstat, 'motivo' => $this->motivo, 'protocolo' => $this->protocolo,
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}

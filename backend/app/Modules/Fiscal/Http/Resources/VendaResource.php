<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Http\Resources;

use App\Modules\Fiscal\Domain\Models\Nota;
use App\Modules\Fiscal\Domain\Models\Venda;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Venda */
final class VendaResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'cliente' => $this->whenLoaded('cliente', fn (): array => ['id' => $this->cliente->id, 'nome' => $this->cliente->nome]),
            'desconto_centavos' => $this->desconto_centavos,
            'subtotal_centavos' => $this->subtotal_centavos,
            'total_centavos' => $this->total_centavos,
            'notas' => $this->whenLoaded('notas', fn (): array => $this->notas->map(fn (Nota $n): array => [
                'id' => $n->id, 'modelo' => $n->modelo->value, 'status' => $n->status->value, 'numero' => $n->numero, 'serie' => $n->serie,
                'subtotal_centavos' => $n->subtotal_centavos, 'desconto_centavos' => $n->desconto_centavos, 'total_centavos' => $n->total_centavos,
            ])->all()),
        ];
    }
}

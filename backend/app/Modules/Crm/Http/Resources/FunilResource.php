<?php

declare(strict_types=1);

namespace App\Modules\Crm\Http\Resources;

use App\Modules\Crm\Domain\Models\Funil;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Funil */
final class FunilResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'nome' => $this->nome,
            'padrao' => $this->padrao,
            'ativo' => $this->ativo,
            'posicao' => $this->posicao,
            'etapas' => EtapaResource::collection($this->whenLoaded('etapas')),
        ];
    }
}

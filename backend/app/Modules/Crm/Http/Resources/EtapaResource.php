<?php

declare(strict_types=1);

namespace App\Modules\Crm\Http\Resources;

use App\Modules\Crm\Domain\Models\Etapa;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Etapa */
final class EtapaResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'funil_id' => $this->funil_id,
            'nome' => $this->nome,
            'posicao' => $this->posicao,
            'probabilidade' => $this->probabilidade,
            'ativa' => $this->ativa,
            'dias_para_parado' => $this->dias_para_parado,
        ];
    }
}

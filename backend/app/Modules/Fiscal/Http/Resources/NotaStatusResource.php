<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Http\Resources;

use App\Modules\Fiscal\Domain\Models\Nota;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Nota */
final class NotaStatusResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id, 'status' => $this->status->value, 'numero' => $this->numero, 'serie' => $this->serie,
            'chave' => $this->chave, 'cstat' => $this->cstat, 'motivo' => $this->motivo, 'mensagem_erro' => $this->mensagem_erro,
            'cancelamento_cstat' => $this->cancelamento_cstat, 'cancelamento_motivo' => $this->cancelamento_motivo,
        ];
    }
}

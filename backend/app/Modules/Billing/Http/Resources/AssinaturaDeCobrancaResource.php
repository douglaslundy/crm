<?php

declare(strict_types=1);

namespace App\Modules\Billing\Http\Resources;

use App\Modules\Billing\Domain\Models\Assinatura;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Assinatura */
final class AssinaturaDeCobrancaResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'plano_id' => $this->plano_id,
            'ciclo' => $this->ciclo->value,
            'metodo' => $this->metodo->value,
            'situacao' => $this->situacao->value,
            'paga_ate' => $this->paga_ate?->toDateString(),
            'cancelada_em' => $this->cancelada_em?->toIso8601String(),
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Crm\Http\Resources;

use App\Modules\Crm\Domain\Models\MotivoDePerda;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin MotivoDePerda */
final class MotivoDePerdaResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'nome' => $this->nome, 'ativo' => $this->ativo];
    }
}

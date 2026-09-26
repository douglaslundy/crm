<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Resources;

use App\Modules\Identity\Domain\Models\Usuario;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Usuario */
final class UsuarioResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'nome' => $this->nome,
            'email' => $this->email,
            'papel' => $this->papel->value,
            'tenant' => $this->tenant === null ? null : [
                'id' => $this->tenant->id,
                'nome' => $this->tenant->nome,
                'cnpj' => $this->tenant->cnpj,
            ],
        ];
    }
}

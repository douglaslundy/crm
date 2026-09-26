<?php

declare(strict_types=1);

namespace App\Modules\Platform\Http\Resources;

use App\Modules\Platform\Domain\Enums\Recurso;
use App\Modules\Platform\Domain\Models\Plano;
use App\Modules\Platform\Domain\Models\PlanoModulo;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Plano */
final class PlanoResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $limites = [];
        foreach (Recurso::cases() as $recurso) {
            $limites[$recurso->value] = $this->limiteDe($recurso);
        }

        return [
            'id' => $this->id,
            'nome' => $this->nome,
            'descricao' => $this->descricao,
            'preco_mensal_centavos' => $this->preco_mensal_centavos,
            'preco_anual_centavos' => $this->preco_anual_centavos,
            'dias_teste' => $this->dias_teste,
            'politica_excedente' => $this->politica_excedente->value,
            'preco_documento_excedente_centavos' => $this->preco_documento_excedente_centavos,
            'modulos' => $this->modulos->map(fn (PlanoModulo $m): string => $m->modulo->value)->values()->all(),
            'limites' => $limites,
            'ativo' => $this->ativo,
            'visivel' => $this->visivel,
            'ordem' => $this->ordem,
            'empresas' => $this->whenCounted('tenants'),
        ];
    }
}

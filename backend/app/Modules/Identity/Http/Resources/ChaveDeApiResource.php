<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Resources;

use App\Modules\Identity\Domain\Models\ChaveDeApi;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Nunca expõe o hash. O texto da chave entra só na resposta da criação, pelo parâmetro `$texto`.
 *
 * @mixin ChaveDeApi
 */
final class ChaveDeApiResource extends JsonResource
{
    private ?string $texto = null;

    /** Não é argumento do construtor: a coleção do Laravel passa o índice como segundo argumento. */
    public function comChave(string $texto): self
    {
        $this->texto = $texto;

        return $this;
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $dados = [
            'id' => $this->id,
            'nome' => $this->nome,
            'ambiente' => $this->ambiente->value,
            'prefixo' => $this->prefixo,
            'ultimos4' => $this->ultimos4,
            'usuario' => $this->whenLoaded('usuario', fn (): array => ['id' => $this->usuario->id, 'nome' => $this->usuario->nome]),
            'ultimo_uso_em' => $this->ultimo_uso_em?->toIso8601String(),
            'revogada_em' => $this->revogada_em?->toIso8601String(),
            'criada_em' => $this->created_at?->toIso8601String(),
        ];

        if ($this->texto !== null) {
            $dados['chave'] = $this->texto;
        }

        return $dados;
    }
}

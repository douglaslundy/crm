<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Http\Resources;

use App\Modules\Fiscal\Domain\Models\WebhookEndpoint;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * O segredo completo só entra na resposta da criação (`comSegredo`); em qualquer outra leitura há só os 4 últimos caracteres.
 *
 * @mixin WebhookEndpoint
 */
final class WebhookResource extends JsonResource
{
    private ?string $segredoNovo = null;

    /** Não é argumento do construtor: a coleção do Laravel passa o índice como segundo argumento. */
    public function comSegredo(string $segredo): self
    {
        $this->segredoNovo = $segredo;

        return $this;
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $dados = [
            'id' => $this->id,
            'url' => $this->url,
            'descricao' => $this->descricao,
            'eventos' => $this->eventos,
            'ambiente' => $this->ambiente->value,
            'ativo' => $this->ativo,
            'desativado_em' => $this->desativado_em?->toIso8601String(),
            'motivo_desativacao' => $this->motivo_desativacao,
            'segredo_final' => substr($this->segredo, -4),
            'criado_em' => $this->created_at?->toIso8601String(),
        ];

        if ($this->segredoNovo !== null) {
            $dados['segredo'] = $this->segredoNovo;
        }

        return $dados;
    }
}

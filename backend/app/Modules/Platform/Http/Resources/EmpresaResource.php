<?php

declare(strict_types=1);

namespace App\Modules\Platform\Http\Resources;

use App\Modules\Tenancy\Domain\Models\Tenant;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Tenant */
final class EmpresaResource extends JsonResource
{
    /** @param list<array{recurso: string, uso: int, limite: int}>|null $consumo */
    public function __construct(Tenant $resource, private readonly ?array $consumo = null)
    {
        parent::__construct($resource);
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'cnpj' => $this->cnpj,
            'razao_social' => $this->razao_social,
            'nome_fantasia' => $this->nome_fantasia,
            'situacao' => $this->situacao->value,
            'teste_termina_em' => $this->teste_termina_em?->toDateString(),
            'situacao_alterada_em' => $this->situacao_alterada_em?->toIso8601String(),
            'plano' => $this->plano === null ? null : [
                'id' => $this->plano->id,
                'nome' => $this->plano->nome,
                'preco_mensal_centavos' => $this->plano->preco_mensal_centavos,
            ],
            'criada_em' => $this->created_at->toIso8601String(),
            'consumo' => $this->when($this->consumo !== null, $this->consumo),
        ];
    }
}

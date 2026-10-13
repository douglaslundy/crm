<?php

declare(strict_types=1);

namespace App\Modules\Crm\Application;

use App\Modules\Crm\Domain\Contracts\ConsultaDeClientes;
use App\Modules\Crm\Domain\Models\ItemDaProposta;
use App\Modules\Crm\Domain\Models\Proposta;
use App\Modules\Tenancy\Domain\Models\Tenant;

final class ApresentadorDePropostas
{
    public function __construct(private readonly ConsultaDeClientes $clientes) {}

    /**
     * Visão do vendedor. O `link` só vem quando pedido e quando a proposta tem token (rascunho e cancelada não têm).
     *
     * @return array<string, mixed>
     */
    public function apresentar(Proposta $proposta, bool $comLink = false): array
    {
        $proposta->loadMissing('itens');

        $dados = [
            'id' => $proposta->id,
            'numero' => $proposta->numero,
            'status' => $proposta->statusApresentado(),
            'negocio_id' => $proposta->negocio_id,
            'cliente_id' => $proposta->cliente_id,
            'validade_dias' => $proposta->validade_dias,
            'validade_em' => $proposta->validade_em?->toIso8601String(),
            'desconto_centavos' => $proposta->desconto_centavos,
            'subtotal_centavos' => $proposta->subtotalCentavos(),
            'total_centavos' => $proposta->totalCentavos(),
            'observacoes' => $proposta->observacoes,
            'enviada_em' => $proposta->enviada_em?->toIso8601String(),
            'respondida_em' => $proposta->respondida_em?->toIso8601String(),
            'aceite_nome' => $proposta->aceite_nome,
            'motivo_recusa' => $proposta->motivo_recusa,
            'notas_ids' => $proposta->notas_ids ?? [],
            'itens' => $this->itens($proposta),
        ];
        if ($comLink) {
            $dados['link'] = $proposta->token !== null ? $this->link($proposta->token) : null;
        }

        return $dados;
    }

    /**
     * Visão do cliente, sem login: só o que está na proposta. Nenhum id interno nem dado de usuário.
     *
     * @return array<string, mixed>
     */
    public function paraPublico(Proposta $proposta): array
    {
        $proposta->loadMissing('itens');
        $tenant = Tenant::query()->findOrFail($proposta->tenant_id);

        return [
            'numero' => $proposta->numero,
            'status' => $proposta->statusApresentado(),
            'empresa' => ['nome' => $tenant->nome_fantasia ?? $tenant->razao_social, 'cnpj' => $tenant->cnpj],
            'cliente' => ['nome' => $this->clientes->resumo($proposta->cliente_id)?->nome],
            'validade_em' => $proposta->validade_em?->toIso8601String(),
            'itens' => $this->itens($proposta),
            'subtotal_centavos' => $proposta->subtotalCentavos(),
            'desconto_centavos' => $proposta->desconto_centavos,
            'total_centavos' => $proposta->totalCentavos(),
            'observacoes' => $proposta->observacoes,
            'aceite_nome' => $proposta->aceite_nome,
            'respondida_em' => $proposta->respondida_em?->toIso8601String(),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function itens(Proposta $proposta): array
    {
        return $proposta->itens->map(fn (ItemDaProposta $i): array => [
            'tipo' => $i->tipo->value,
            'descricao' => $i->descricao,
            'quantidade' => $i->quantidade,
            'valor_unitario_centavos' => $i->valor_unitario_centavos,
            'total_centavos' => $i->totalCentavos(),
        ])->values()->all();
    }

    private function link(string $token): string
    {
        return rtrim((string) config('app.frontend_url', config('app.url')), '/').'/p/'.$token;
    }
}

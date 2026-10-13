<?php

declare(strict_types=1);

namespace App\Modules\Crm\Application;

use App\Modules\Crm\Domain\Contracts\ConsultaDeClientes;
use App\Modules\Crm\Domain\Contracts\DiretorioDeUsuarios;
use App\Modules\Crm\Domain\Models\Negocio;

/** Monta o JSON do negócio buscando clientes e responsáveis em lote, pelos contratos. */
final class ApresentadorDeNegocios
{
    public function __construct(
        private readonly ConsultaDeClientes $clientes,
        private readonly DiretorioDeUsuarios $usuarios,
        private readonly ConsultorDeParados $parados,
    ) {}

    /**
     * @param  iterable<Negocio>  $negocios
     * @return list<array<string, mixed>>
     */
    public function apresentar(iterable $negocios): array
    {
        $lista = [...$negocios];
        $clientes = $this->clientes->resumos(array_values(array_unique(array_map(fn (Negocio $n): string => $n->cliente_id, $lista))));
        $nomes = $this->usuarios->nomes(array_values(array_unique(array_map(fn (Negocio $n): string => $n->responsavel_id, $lista))));

        $situacao = $this->parados->situacao($lista);

        return array_map(function (Negocio $n) use ($clientes, $nomes, $situacao): array {
            $cliente = $clientes[$n->cliente_id] ?? null;

            return [
                'id' => $n->id,
                'titulo' => $n->titulo,
                'cliente' => $cliente === null ? null : [
                    'id' => $cliente->id,
                    'nome' => $cliente->nome,
                    'telefone' => $cliente->telefone,
                    'email' => $cliente->email,
                    'estagio' => $cliente->ehLead ? 'LEAD' : 'CLIENTE',
                ],
                'funil_id' => $n->funil_id,
                'etapa_id' => $n->etapa_id,
                'responsavel' => isset($nomes[$n->responsavel_id]) ? ['id' => $n->responsavel_id, 'nome' => $nomes[$n->responsavel_id]] : null,
                'status' => $n->status->value,
                'valor_centavos' => $n->valor_centavos,
                'previsao_fechamento' => $n->previsao_fechamento?->format('Y-m-d'),
                'posicao_na_etapa' => $n->posicao_na_etapa,
                'motivo_de_perda_id' => $n->motivo_de_perda_id,
                'observacao_da_perda' => $n->observacao_da_perda,
                'fechado_em' => $n->fechado_em?->toIso8601String(),
                'parado' => $situacao[$n->id]['parado'] ?? false,
                'dias_parado' => $situacao[$n->id]['dias'] ?? null,
                'itens' => $n->itens->map(fn ($i): array => [
                    'id' => $i->id,
                    'tipo' => $i->tipo->value,
                    'item_id' => $i->item_id,
                    'descricao' => $i->descricao,
                    'quantidade' => $i->quantidade,
                    'valor_unitario_centavos' => $i->valor_unitario_centavos,
                ])->values()->all(),
            ];
        }, $lista);
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Crm\Application;

use App\Modules\Crm\Domain\Contracts\ConsultaDeClientes;
use App\Modules\Crm\Domain\Contracts\DiretorioDeUsuarios;
use App\Modules\Crm\Domain\Models\Atividade;
use App\Modules\Crm\Domain\Models\Negocio;

/** Monta o JSON da atividade com cliente, negócio e responsável em lote. */
final class ApresentadorDeAtividades
{
    public function __construct(
        private readonly ConsultaDeClientes $clientes,
        private readonly DiretorioDeUsuarios $usuarios,
    ) {}

    /**
     * @param  iterable<Atividade>  $atividades
     * @return list<array<string, mixed>>
     */
    public function apresentar(iterable $atividades): array
    {
        $lista = [...$atividades];
        $clientes = $this->clientes->resumos(array_values(array_unique(array_map(fn (Atividade $a): string => $a->cliente_id, $lista))));
        $nomes = $this->usuarios->nomes(array_values(array_unique(array_map(fn (Atividade $a): string => $a->responsavel_id, $lista))));
        $negocioIds = array_values(array_unique(array_filter(array_map(fn (Atividade $a): ?string => $a->negocio_id, $lista))));
        /** @var array<string, string> $titulos */
        $titulos = $negocioIds === [] ? [] : Negocio::query()->whereIn('id', $negocioIds)->pluck('titulo', 'id')->all();
        $agora = now();

        return array_map(function (Atividade $a) use ($clientes, $nomes, $titulos, $agora): array {
            $cliente = $clientes[$a->cliente_id] ?? null;

            return [
                'id' => $a->id,
                'tipo' => $a->tipo->value,
                'titulo' => $a->titulo,
                'descricao' => $a->descricao,
                'agendada_para' => $a->agendada_para->toIso8601String(),
                'concluida_em' => $a->concluida_em?->toIso8601String(),
                'atrasada' => ! $a->estaConcluida() && $a->agendada_para->lt($agora),
                'cliente' => $cliente === null ? null : [
                    'id' => $cliente->id, 'nome' => $cliente->nome, 'telefone' => $cliente->telefone, 'email' => $cliente->email,
                ],
                'negocio' => $a->negocio_id !== null && isset($titulos[$a->negocio_id])
                    ? ['id' => $a->negocio_id, 'titulo' => $titulos[$a->negocio_id]] : null,
                'responsavel' => isset($nomes[$a->responsavel_id]) ? ['id' => $a->responsavel_id, 'nome' => $nomes[$a->responsavel_id]] : null,
            ];
        }, $lista);
    }
}

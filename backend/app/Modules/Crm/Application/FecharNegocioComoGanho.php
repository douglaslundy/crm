<?php

declare(strict_types=1);

namespace App\Modules\Crm\Application;

use App\Modules\Crm\Domain\Contracts\ConsultaDeClientes;
use App\Modules\Crm\Domain\Enums\StatusDoNegocio;
use App\Modules\Crm\Domain\Events\NegocioGanho;
use App\Modules\Crm\Domain\Models\Negocio;
use Illuminate\Support\Facades\DB;

/** Núcleo de "ganhar negócio", sem política de acesso: quem chama já autorizou (vendedor ou aceite público). */
final class FecharNegocioComoGanho
{
    public function __construct(private readonly ConsultaDeClientes $clientes) {}

    /** Chamar dentro de uma transação: o evento só sai depois do commit. */
    public function executar(Negocio $negocio, ?string $autorId): Negocio
    {
        $negocio = Negocio::query()->lockForUpdate()->findOrFail($negocio->id);
        $negocio->garantirAberto();

        $negocio->update(['status' => StatusDoNegocio::Ganho, 'fechado_em' => now()]);

        if ($this->clientes->resumo($negocio->cliente_id)?->ehLead === true) {
            $this->clientes->converterEmCliente($negocio->cliente_id);
        }

        DB::afterCommit(fn () => event(new NegocioGanho($negocio->tenant_id, $negocio->id, $negocio->cliente_id, $autorId)));

        return $negocio->refresh()->load('itens');
    }
}

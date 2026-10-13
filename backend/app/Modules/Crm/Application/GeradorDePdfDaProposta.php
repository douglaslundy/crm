<?php

declare(strict_types=1);

namespace App\Modules\Crm\Application;

use App\Modules\Crm\Domain\Contracts\ConsultaDeClientes;
use App\Modules\Crm\Domain\Models\Proposta;
use App\Modules\Tenancy\Domain\Models\Tenant;
use Barryvdh\DomPDF\Facade\Pdf;

/** PDF comercial a partir do snapshot da proposta. Sem cálculo fiscal. */
final class GeradorDePdfDaProposta
{
    public function __construct(private readonly ConsultaDeClientes $clientes) {}

    public function gerar(Proposta $proposta): string
    {
        $proposta->loadMissing('itens');

        return Pdf::loadView('pdf.proposta', [
            'proposta' => $proposta,
            'tenant' => Tenant::query()->findOrFail($proposta->tenant_id),
            'cliente' => $this->clientes->resumo($proposta->cliente_id),
        ])->setPaper([0, 0, 595, 842], 'portrait')->output();
    }
}

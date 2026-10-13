<?php

declare(strict_types=1);

namespace App\Modules\Crm\Application;

use App\Modules\Crm\Domain\Models\Proposta;
use App\Modules\Tenancy\Domain\TenantContext;
use Illuminate\Database\Eloquent\ModelNotFoundException;

final class ResolvedorDePropostaPublica
{
    /**
     * Única leitura cross-tenant do módulo: o hash do token identifica a proposta e fixa o tenant antes de qualquer
     * outra consulta. Token vazio, inexistente ou de proposta cancelada (hash nulo) não se distingue.
     */
    public function resolver(string $token): Proposta
    {
        $proposta = $token === ''
            ? null
            : Proposta::withoutTenantScope()->where('token_hash', hash('sha256', $token))->first();

        if ($proposta === null) {
            throw (new ModelNotFoundException)->setModel(Proposta::class);
        }

        app(TenantContext::class)->set($proposta->tenant_id);

        return $proposta->load('itens');
    }
}

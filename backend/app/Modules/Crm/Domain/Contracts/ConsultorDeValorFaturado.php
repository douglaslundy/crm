<?php

declare(strict_types=1);

namespace App\Modules\Crm\Domain\Contracts;

use App\Modules\Identity\Domain\Models\Usuario;

/** Implementado por Fiscal. Só leitura, sempre dentro do tenant do contexto atual. */
interface ConsultorDeValorFaturado
{
    /** O plano do tenant inclui NF-e ou NFS-e? Sem isso o faturado não existe. */
    public function disponivel(Usuario $autor): bool;

    /**
     * Soma o total das notas **autorizadas** entre os ids informados. Outros status não contam.
     *
     * @param  list<string>  $notasIds
     */
    public function totalAutorizadoCentavos(array $notasIds): int;
}

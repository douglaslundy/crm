<?php

declare(strict_types=1);

namespace App\Modules\Crm\Domain\Contracts;

use App\Modules\Crm\Domain\LinhaDeProposta;
use App\Modules\Identity\Domain\Models\Usuario;

/** Implementado por Fiscal. O CRM nunca monta nota: só entrega cliente, itens e desconto. */
interface GeradorDeRascunhoDeNota
{
    /** O plano do tenant tem algum módulo de emissão (NF-e ou NFS-e)? */
    public function disponivel(Usuario $autor): bool;

    /**
     * Cria um rascunho por tipo de item (produtos viram NFE, serviços viram DPS) e devolve os ids.
     * Pagamento não é definido aqui: o vendedor completa no editor fiscal.
     *
     * @param  list<LinhaDeProposta>  $linhas
     * @return list<string>
     */
    public function gerar(Usuario $autor, string $clienteId, array $linhas, int $descontoCentavos): array;
}

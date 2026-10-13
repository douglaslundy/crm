<?php

declare(strict_types=1);

namespace App\Modules\Crm\Domain\Contracts;

use App\Modules\Crm\Domain\ResumoDoCliente;
use App\Modules\Identity\Domain\Models\Usuario;

/** Implementado por Customers. O Crm nunca toca o Model de cliente. */
interface ConsultaDeClientes
{
    public function resumo(string $id): ?ResumoDoCliente;

    /**
     * @param  list<string>  $ids
     * @return array<string, ResumoDoCliente> mapa id => resumo; ids inexistentes no tenant ficam de fora
     */
    public function resumos(array $ids): array;

    /** Cria um lead (`estagio = LEAD`) e devolve o id. */
    public function criarLead(Usuario $autor, string $nome, ?string $telefone, ?string $email): string;

    public function converterEmCliente(string $id): void;
}

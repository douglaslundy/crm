<?php

declare(strict_types=1);

namespace App\Modules\Crm\Domain\Contracts;

/** Implementado por Identity. Sempre dentro do tenant do contexto atual. */
interface DiretorioDeUsuarios
{
    /** Usuário ativo da empresa atual. */
    public function ehDaEmpresa(string $id): bool;

    /**
     * @param  list<string>  $ids
     * @return array<string, string> mapa id => nome
     */
    public function nomes(array $ids): array;
}

<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application;

use App\Modules\Crm\Domain\Contracts\DiretorioDeUsuarios;
use App\Modules\Identity\Domain\Models\Usuario;
use App\Modules\Tenancy\Domain\TenantContext;

final class DiretorioDeUsuariosDoIdentity implements DiretorioDeUsuarios
{
    public function __construct(private readonly TenantContext $contexto) {}

    public function ehDaEmpresa(string $id): bool
    {
        return Usuario::query()
            ->where('tenant_id', $this->contexto->require())
            ->where('ativo', true)
            ->whereKey($id)
            ->exists();
    }

    public function nomes(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        /** @var array<string, string> $nomes */
        $nomes = Usuario::query()
            ->where('tenant_id', $this->contexto->require())
            ->whereIn('id', $ids)
            ->pluck('nome', 'id')
            ->all();

        return $nomes;
    }
}

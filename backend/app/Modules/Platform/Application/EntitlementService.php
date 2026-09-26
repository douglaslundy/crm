<?php

declare(strict_types=1);

namespace App\Modules\Platform\Application;

use App\Modules\Platform\Domain\Enums\Modulo;
use App\Modules\Platform\Domain\Enums\Recurso;
use App\Modules\Platform\Domain\Exceptions\LimiteDoPlanoAtingidoException;
use App\Modules\Platform\Domain\Exceptions\ModuloNaoContratadoException;
use App\Modules\Platform\Domain\Models\Plano;
use App\Modules\Tenancy\Domain\Models\Tenant;

/**
 * O que o plano do tenant permite. Chamado pelas Actions (nunca só pelo
 * controller), para valer igual no frontend e na API pública.
 */
final class EntitlementService
{
    public function __construct(private readonly RegistroDeContadores $contadores) {}

    public function temModulo(Tenant $tenant, Modulo $modulo): bool
    {
        return $tenant->plano?->temModulo($modulo) ?? false;
    }

    public function garantirModulo(Tenant $tenant, Modulo $modulo): void
    {
        if (! $this->temModulo($tenant, $modulo)) {
            throw ModuloNaoContratadoException::para($modulo);
        }
    }

    /** -1 = ilimitado; sem plano ou sem linha = 0. */
    public function limite(Tenant $tenant, Recurso $recurso): int
    {
        return $tenant->plano?->limiteDe($recurso) ?? 0;
    }

    public function uso(Tenant $tenant, Recurso $recurso): int
    {
        return $this->contadores->para($recurso)->contar($tenant);
    }

    public function garantirCapacidade(Tenant $tenant, Recurso $recurso, int $adicionar = 1): void
    {
        $limite = $this->limite($tenant, $recurso);

        if ($limite === Plano::ILIMITADO) {
            return;
        }

        if ($this->uso($tenant, $recurso) + $adicionar > $limite) {
            throw LimiteDoPlanoAtingidoException::para($recurso, $limite);
        }
    }

    /** @return list<array{recurso: string, uso: int, limite: int}> */
    public function consumo(Tenant $tenant, ?Plano $plano = null): array
    {
        $plano ??= $tenant->plano;

        return array_map(fn (Recurso $recurso): array => [
            'recurso' => $recurso->value,
            'uso' => $this->uso($tenant, $recurso),
            'limite' => $plano?->limiteDe($recurso) ?? 0,
        ], $this->contadores->recursos());
    }

    /** @return list<array{recurso: string, uso: int, limite: int}> */
    public function excessos(Tenant $tenant, Plano $plano): array
    {
        return array_values(array_filter(
            $this->consumo($tenant, $plano),
            fn (array $item): bool => $item['limite'] !== Plano::ILIMITADO && $item['uso'] > $item['limite'],
        ));
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Billing\Application\Actions;

use App\Modules\Billing\Application\PoliticaDeCobranca;
use App\Modules\Billing\Domain\Models\Cobranca;
use App\Modules\Identity\Domain\Models\Usuario;
use Illuminate\Database\Eloquent\Collection;

final class ListarCobrancas
{
    public function __construct(private readonly PoliticaDeCobranca $politica) {}

    /** @return Collection<int, Cobranca> faturas do tenant, as de vencimento mais recente primeiro */
    public function executar(Usuario $autor): Collection
    {
        $this->politica->garantirPodeGerenciar($autor);

        return Cobranca::query()->orderByDesc('vence_em')->orderByDesc('created_at')->get();
    }
}

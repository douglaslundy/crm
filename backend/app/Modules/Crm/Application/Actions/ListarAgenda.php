<?php

declare(strict_types=1);

namespace App\Modules\Crm\Application\Actions;

use App\Modules\Crm\Application\PoliticaDoCrm;
use App\Modules\Crm\Domain\Models\Atividade;
use App\Modules\Identity\Domain\Models\Usuario;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

final class ListarAgenda
{
    public function __construct(private readonly PoliticaDoCrm $politica) {}

    /**
     * Abertas até o fim de `$ate`: as do intervalo e todas as atrasadas (agendadas antes de agora).
     *
     * @return Collection<int, Atividade>
     */
    public function executar(Usuario $autor, CarbonInterface $de, CarbonInterface $ate, ?string $responsavelId): Collection
    {
        $this->politica->garantirPodeLer($autor);

        $consulta = Atividade::query()->visiveisPara($autor)
            ->whereNull('concluida_em')
            ->where('agendada_para', '<=', $ate->copy()->utc())
            ->where(function ($q) use ($de): void {
                $q->where('agendada_para', '>=', $de->copy()->utc())->orWhere('agendada_para', '<', now());
            });

        if ($responsavelId !== null) {
            $consulta->where('responsavel_id', $responsavelId);
        }

        return $consulta->orderBy('agendada_para')->orderBy('id')->get();
    }
}

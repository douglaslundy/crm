<?php

declare(strict_types=1);

namespace App\Modules\Crm\Application\Actions;

use App\Modules\Crm\Application\ConsultorDeParados;
use App\Modules\Crm\Application\PoliticaDoCrm;
use App\Modules\Crm\Domain\Enums\StatusDoNegocio;
use App\Modules\Crm\Domain\Models\Atividade;
use App\Modules\Crm\Domain\Models\Negocio;
use App\Modules\Identity\Domain\Models\Usuario;

final class ResumoDoCrm
{
    public function __construct(
        private readonly PoliticaDoCrm $politica,
        private readonly ConsultorDeParados $parados,
    ) {}

    /** @return array{hoje: int, atrasadas: int, parados: int} */
    public function executar(Usuario $autor): array
    {
        $this->politica->garantirPodeLer($autor);

        $agora = now();
        $fimDoDia = now((string) config('app.fuso_negocio'))->endOfDay()->utc();
        $abertas = fn () => Atividade::query()->visiveisPara($autor)->where('responsavel_id', $autor->id)->whereNull('concluida_em');

        $negocios = Negocio::query()->visiveisPara($autor)->where('status', StatusDoNegocio::Aberto)->get();
        $situacao = $this->parados->situacao($negocios);

        return [
            'hoje' => $abertas()->where('agendada_para', '>=', $agora)->where('agendada_para', '<=', $fimDoDia)->count(),
            'atrasadas' => $abertas()->where('agendada_para', '<', $agora)->count(),
            'parados' => count(array_filter($situacao, fn (array $s): bool => $s['parado'])),
        ];
    }
}

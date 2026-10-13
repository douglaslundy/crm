<?php

declare(strict_types=1);

namespace App\Modules\Crm\Application\Actions;

use App\Modules\Crm\Application\ConsultorDeParados;
use App\Modules\Crm\Application\PoliticaDoCrm;
use App\Modules\Crm\Application\Relatorio\PeriodoDoRelatorio;
use App\Modules\Crm\Application\Relatorio\RelatorioDeNegocios;
use App\Modules\Crm\Domain\Enums\StatusDoNegocio;
use App\Modules\Crm\Domain\Models\Atividade;
use App\Modules\Crm\Domain\Models\Negocio;
use App\Modules\Identity\Domain\Models\Usuario;

final class ResumoDoCrm
{
    public function __construct(
        private readonly PoliticaDoCrm $politica,
        private readonly ConsultorDeParados $parados,
        private readonly RelatorioDeNegocios $relatorio,
    ) {}

    /** @return array<string, mixed> */
    public function executar(Usuario $autor): array
    {
        $this->politica->garantirPodeLer($autor);

        $agora = now();
        $fimDoDia = now((string) config('app.fuso_negocio'))->endOfDay()->utc();
        $abertas = fn () => Atividade::query()->visiveisPara($autor)->where('responsavel_id', $autor->id)->whereNull('concluida_em');

        $negocios = Negocio::query()->visiveisPara($autor)->where('status', StatusDoNegocio::Aberto)->get();
        $situacao = $this->parados->situacao($negocios);
        $mes = PeriodoDoRelatorio::doMes($agora);

        return [
            'hoje' => $abertas()->where('agendada_para', '>=', $agora)->where('agendada_para', '<=', $fimDoDia)->count(),
            'atrasadas' => $abertas()->where('agendada_para', '<', $agora)->count(),
            'parados' => count(array_filter($situacao, fn (array $s): bool => $s['parado'])),
            'funil' => $this->relatorio->funil($autor, null, null),
            'previsao_do_mes' => $this->relatorio->previsao($autor, $mes, null)['no_periodo'],
            'meta_do_mes' => $this->relatorio->metaDoUsuario($autor, $mes),
        ];
    }
}

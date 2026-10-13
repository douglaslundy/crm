<?php

declare(strict_types=1);

namespace App\Modules\Crm\Application\Actions;

use App\Modules\Crm\Application\PoliticaDoCrm;
use App\Modules\Crm\Application\Relatorio\PeriodoDoRelatorio;
use App\Modules\Crm\Application\Relatorio\RelatorioDeNegocios;
use App\Modules\Crm\Application\Relatorio\RelatorioDePropostas;
use App\Modules\Identity\Domain\Models\Usuario;

final class RelatorioComercial
{
    public function __construct(
        private readonly PoliticaDoCrm $politica,
        private readonly RelatorioDeNegocios $negocios,
        private readonly RelatorioDePropostas $propostas,
    ) {}

    /** @return array<string, mixed> */
    public function executar(Usuario $autor, PeriodoDoRelatorio $periodo, ?string $vendedorId, ?string $funilId): array
    {
        $this->politica->garantirPodeLer($autor);

        // Quem não reatribui negócio não escolhe vendedor: o resultado já é o próprio recorte dele.
        $vendedorId = $this->politica->podeReatribuir($autor) ? $vendedorId : null;

        return [
            'periodo' => ['de' => $periodo->inicioData(), 'ate' => $periodo->fimData()],
            'resumo' => $this->negocios->resumo($autor, $periodo, $vendedorId),
            'funil' => $this->negocios->funil($autor, $vendedorId, $funilId),
            'previsao' => $this->negocios->previsao($autor, $periodo, $vendedorId),
            'motivos_de_perda' => $this->negocios->motivosDePerda($autor, $periodo, $vendedorId),
            'vendedores' => $this->negocios->vendedores($autor, $periodo, $vendedorId),
            'propostas' => $this->propostas->propostas($autor, $periodo, $vendedorId),
            'faturado_centavos' => $this->propostas->faturado($autor, $periodo, $vendedorId),
        ];
    }
}

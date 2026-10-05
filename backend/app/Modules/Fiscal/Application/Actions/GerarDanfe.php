<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Application\Actions;

use App\Modules\Fiscal\Application\Emissao\ArquivoPdf;
use App\Modules\Fiscal\Domain\Contracts\GeradorDeDanfe;
use App\Modules\Fiscal\Domain\Enums\ModeloDocumento;
use App\Modules\Fiscal\Domain\Enums\StatusNota;
use App\Modules\Fiscal\Domain\Exceptions\NotaSemPdfException;
use App\Modules\Fiscal\Domain\Models\Nota;

/** O PDF vem sempre do XML (o que foi autorizado), nunca de colunas paralelas, e nada é gravado. */
final class GerarDanfe
{
    public function __construct(private readonly GeradorDeDanfe $gerador) {}

    public function executar(Nota $nota): ArquivoPdf
    {
        $nota->makeVisible(['xml_autorizado', 'xml_cancelamento']);

        [$xml, $cancelada] = match ($nota->status) {
            StatusNota::Autorizada, StatusNota::Cancelando => [$nota->xml_autorizado, false],
            StatusNota::Cancelada => [$this->xmlDaCancelada($nota), true],
            default => throw new NotaSemPdfException,
        };

        if (! is_string($xml) || $xml === '') {
            throw new NotaSemPdfException('O XML desta nota não está disponível para gerar o DANFE ou o cupom.');
        }

        $prefixo = $nota->modelo === ModeloDocumento::Nfce ? 'CUPOM' : 'DANFE';

        return new ArquivoPdf($prefixo.'-'.($nota->numero ?? 'sem-numero').'.pdf', $this->gerador->gerar($xml, $cancelada, $nota->modelo));
    }

    /** O fallback cru da F3b1 (só o retorno do evento) não serve de base: usa o autorizado, ainda com a marca. */
    private function xmlDaCancelada(Nota $nota): ?string
    {
        $cancelamento = $nota->xml_cancelamento;

        return is_string($cancelamento) && str_contains($cancelamento, '<nfeProc') ? $cancelamento : $nota->xml_autorizado;
    }
}

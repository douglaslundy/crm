<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Infrastructure\Pdf;

use App\Modules\Fiscal\Domain\Contracts\GeradorDeDanfe;
use App\Modules\Fiscal\Domain\Enums\ModeloDocumento;
use App\Modules\Fiscal\Domain\Exceptions\PdfIndisponivelException;
use NFePHP\DA\NFe\Danfce;
use NFePHP\DA\NFe\Danfe;
use Throwable;

final class SpedDaGeradorDeDanfe implements GeradorDeDanfe
{
    public function gerar(string $xml, bool $cancelada, ModeloDocumento $modelo = ModeloDocumento::Nfe): string
    {
        try {
            if ($modelo === ModeloDocumento::Nfce) {
                $cupom = new Danfce($xml);
                $cupom->setPaperWidth(80);
                if ($cancelada) {
                    $cupom->setAsCanceled();
                }

                return $cupom->render();
            }

            $danfe = new Danfe($xml);
            $danfe->setCancelFlag($cancelada);

            return $danfe->render();
        } catch (Throwable $e) {
            throw new PdfIndisponivelException($e);
        }
    }
}

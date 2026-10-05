<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Infrastructure\Pdf;

use App\Modules\Fiscal\Domain\Contracts\GeradorDeDanfe;
use App\Modules\Fiscal\Domain\Exceptions\PdfIndisponivelException;
use NFePHP\DA\NFe\Danfe;
use Throwable;

final class SpedDaGeradorDeDanfe implements GeradorDeDanfe
{
    public function gerar(string $xml, bool $cancelada): string
    {
        try {
            $danfe = new Danfe($xml);
            $danfe->setCancelFlag($cancelada);

            return $danfe->render();
        } catch (Throwable $e) {
            throw new PdfIndisponivelException($e);
        }
    }
}

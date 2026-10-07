<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Infrastructure\Pdf;

use App\Modules\Fiscal\Domain\Contracts\GeradorDeDanfse;
use App\Modules\Fiscal\Domain\Exceptions\PdfIndisponivelException;
use App\Modules\Fiscal\Infrastructure\Pdf\Danfse\DanfseRenderer;
use Barryvdh\DomPDF\Facade\Pdf;
use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Writer\PngWriter;
use Throwable;

final class DompdfGeradorDeDanfse implements GeradorDeDanfse
{
    public function __construct(private readonly DanfseRenderer $renderer) {}

    public function gerar(string $xml, bool $cancelada): string
    {
        try {
            $dados = $this->renderer->dadosParaTemplate($xml, $cancelada, $this->qrCode($xml), $this->logo());

            return Pdf::loadView('pdf.danfse', $dados)->setPaper([0, 0, 595, 842], 'portrait')->output();
        } catch (Throwable $e) {
            throw new PdfIndisponivelException($e);
        }
    }

    /** Mesma URL do QR do DANFSe oficial; a zona de silêncio de 22 px reproduz o quadro de 45 pt do original. */
    private function qrCode(string $xml): ?string
    {
        if (preg_match('/<infNFSe[^>]*\sId="NFS(\d{50})"/', $xml, $m) !== 1) {
            return null;
        }

        return (new Builder(
            writer: new PngWriter,
            data: 'https://www.nfse.gov.br/ConsultaPublica?tpc=1&chave='.$m[1],
            size: 450,
            margin: 22,
        ))->build()->getDataUri();
    }

    private function logo(): string
    {
        return 'data:image/png;base64,'.base64_encode((string) file_get_contents(resource_path('images/danfse-logo.png')));
    }
}

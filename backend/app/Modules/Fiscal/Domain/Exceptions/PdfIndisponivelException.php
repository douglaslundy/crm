<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Domain\Exceptions;

use App\Modules\Shared\Domain\Exceptions\ErroDeNegocio;
use Throwable;

final class PdfIndisponivelException extends ErroDeNegocio
{
    public function __construct(?Throwable $causa = null)
    {
        parent::__construct('Não foi possível gerar o PDF desta nota. Baixe o XML ou tente novamente.', 0, $causa);
    }

    public function status(): int
    {
        return 422;
    }

    public function codigo(): string
    {
        return 'PDF_INDISPONIVEL';
    }
}

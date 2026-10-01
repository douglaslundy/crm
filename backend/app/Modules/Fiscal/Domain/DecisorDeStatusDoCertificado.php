<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Domain;

use App\Modules\Fiscal\Domain\Enums\CertificadoStatus;
use Carbon\CarbonInterface;

final class DecisorDeStatusDoCertificado
{
    public static function para(CarbonInterface $validade): CertificadoStatus
    {
        return $validade->isPast() ? CertificadoStatus::Vencido : CertificadoStatus::Valido;
    }
}

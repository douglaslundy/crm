<?php

declare(strict_types=1);

namespace App\Modules\Billing\Domain;

enum TipoDeTroca: string
{
    case Upgrade = 'UPGRADE';
    case Downgrade = 'DOWNGRADE';
}

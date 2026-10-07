<?php

declare(strict_types=1);

namespace App\Modules\Billing\Application\Actions;

use App\Modules\Billing\Domain\Models\Assinatura;
use App\Modules\Billing\Domain\Models\Cobranca;

final readonly class ResultadoDoCheckout
{
    public function __construct(public Assinatura $assinatura, public Cobranca $cobranca) {}
}

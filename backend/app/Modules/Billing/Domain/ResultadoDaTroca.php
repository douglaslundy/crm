<?php

declare(strict_types=1);

namespace App\Modules\Billing\Domain;

use App\Modules\Billing\Domain\Models\Assinatura;
use App\Modules\Billing\Domain\Models\Cobranca;

/** Upgrade devolve a cobrança a pagar; downgrade devolve a assinatura com o agendamento. */
final readonly class ResultadoDaTroca
{
    public function __construct(public TipoDeTroca $tipo, public Assinatura $assinatura, public ?Cobranca $cobranca = null) {}
}

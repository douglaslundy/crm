<?php

declare(strict_types=1);

namespace App\Modules\Billing\Domain\Gateway;

final readonly class ResultadoDeAssinatura
{
    public function __construct(public string $id, public bool $autorizada) {}
}

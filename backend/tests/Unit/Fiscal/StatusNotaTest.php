<?php

declare(strict_types=1);

namespace Tests\Unit\Fiscal;

use App\Modules\Fiscal\Domain\Enums\StatusNota;
use PHPUnit\Framework\TestCase;

class StatusNotaTest extends TestCase
{
    public function test_cancelando_nao_e_editavel(): void
    {
        $this->assertSame('CANCELANDO', StatusNota::Cancelando->value);
        $this->assertFalse(StatusNota::Cancelando->editavel());
    }

    public function test_so_rascunho_rejeitada_e_erro_sao_editaveis(): void
    {
        $editaveis = array_filter(StatusNota::cases(), fn (StatusNota $s): bool => $s->editavel());
        $this->assertEqualsCanonicalizing(
            [StatusNota::Rascunho, StatusNota::Rejeitada, StatusNota::Erro],
            array_values($editaveis),
        );
    }
}

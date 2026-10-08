<?php

declare(strict_types=1);

namespace Tests\Unit\Billing;

use App\Modules\Billing\Domain\Enums\CicloDeCobranca;
use App\Modules\Billing\Domain\Exceptions\BillingException;
use App\Modules\Billing\Domain\ValorDoUpgrade;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ValorDoUpgradeTest extends TestCase
{
    /** @return array<string, array{int, int, string, string, CicloDeCobranca, int}> */
    public static function casos(): array
    {
        return [
            'mensal de 30 dias, 15 restantes' => [10000, 16000, '2026-10-15', '2026-10-30', CicloDeCobranca::Mensal, 3000],
            'mensal de 28 dias (fevereiro)' => [10000, 12800, '2026-02-15', '2026-03-01', CicloDeCobranca::Mensal, 1400],
            'mensal de 31 dias' => [10000, 13100, '2026-07-17', '2026-08-01', CicloDeCobranca::Mensal, 1500],
            'anual de 365 dias' => [100000, 136500, '2026-07-02', '2027-01-01', CicloDeCobranca::Anual, 18300],
            'meio centavo sobe' => [10000, 10015, '2026-10-29', '2026-10-30', CicloDeCobranca::Mensal, 1],
            'toda a diferença no primeiro dia' => [10000, 16000, '2026-09-30', '2026-10-30', CicloDeCobranca::Mensal, 6000],
        ];
    }

    #[DataProvider('casos')]
    public function test_calcula_a_diferenca_proporcional_aos_dias_restantes(int $atual, int $novo, string $hoje, string $pagaAte, CicloDeCobranca $ciclo, int $esperado): void
    {
        $this->assertSame($esperado, ValorDoUpgrade::calcular($atual, $novo, CarbonImmutable::parse($hoje), CarbonImmutable::parse($pagaAte), $ciclo));
    }

    /** @return array<string, array{string, string, int}> */
    public static function recusas(): array
    {
        return [
            'renovação hoje' => ['2026-10-30', '2026-10-30', 6000],
            'período já vencido' => ['2026-11-02', '2026-10-30', 6000],
            'diferença que arredonda para zero' => ['2026-10-29', '2026-10-30', 10],
        ];
    }

    #[DataProvider('recusas')]
    public function test_recusa_quando_nao_ha_o_que_cobrar(string $hoje, string $pagaAte, int $diferenca): void
    {
        try {
            ValorDoUpgrade::calcular(10000, 10000 + $diferenca, CarbonImmutable::parse($hoje), CarbonImmutable::parse($pagaAte), CicloDeCobranca::Mensal);
            $this->fail('deveria recusar');
        } catch (BillingException $e) {
            $this->assertSame('BILLING_TROCA_SEM_DIAS', $e->codigo());
        }
    }
}

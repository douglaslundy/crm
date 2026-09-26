<?php

declare(strict_types=1);

namespace Tests\Unit\Tenancy;

use App\Modules\Tenancy\Domain\Enums\SituacaoAssinatura as S;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SituacaoAssinaturaTest extends TestCase
{
    /** @return array<string, array{S, S}> */
    public static function validas(): array
    {
        return [
            'pendente → ativa' => [S::Pendente, S::Ativa],
            'teste → ativa' => [S::Teste, S::Ativa],
            'teste → suspensa' => [S::Teste, S::Suspensa],
            'ativa → suspensa' => [S::Ativa, S::Suspensa],
            'inadimplente → suspensa' => [S::Inadimplente, S::Suspensa],
            'suspensa → ativa' => [S::Suspensa, S::Ativa],
            'pendente → cancelada' => [S::Pendente, S::Cancelada],
            'teste → cancelada' => [S::Teste, S::Cancelada],
            'ativa → cancelada' => [S::Ativa, S::Cancelada],
            'inadimplente → cancelada' => [S::Inadimplente, S::Cancelada],
            'suspensa → cancelada' => [S::Suspensa, S::Cancelada],
        ];
    }

    #[DataProvider('validas')]
    public function test_transicoes_validas(S $de, S $para): void
    {
        $this->assertTrue($de->podeIrPara($para));
    }

    /** @return array<string, array{S, S}> */
    public static function invalidas(): array
    {
        return [
            'pendente → suspensa' => [S::Pendente, S::Suspensa],
            'ativa → teste' => [S::Ativa, S::Teste],
            'cancelada → ativa' => [S::Cancelada, S::Ativa],
            'cancelada → cancelada' => [S::Cancelada, S::Cancelada],
            'ativa → ativa' => [S::Ativa, S::Ativa],
            'suspensa → inadimplente' => [S::Suspensa, S::Inadimplente],
        ];
    }

    #[DataProvider('invalidas')]
    public function test_transicoes_invalidas(S $de, S $para): void
    {
        $this->assertFalse($de->podeIrPara($para));
    }

    public function test_escrita_so_em_teste_ativa_e_inadimplente(): void
    {
        $comEscrita = array_values(array_filter(S::cases(), fn (S $s): bool => $s->permiteEscrita()));

        $this->assertSame([S::Teste, S::Ativa, S::Inadimplente], $comEscrita);
    }
}

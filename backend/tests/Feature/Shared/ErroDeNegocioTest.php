<?php

declare(strict_types=1);

namespace Tests\Feature\Shared;

use App\Modules\Shared\Domain\Exceptions\AcessoNegadoException;
use App\Modules\Shared\Domain\Exceptions\ErroDeNegocio;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class ErroDeNegocioTest extends TestCase
{
    public function test_erro_de_negocio_vira_json_com_codigo_e_extras(): void
    {
        Route::get('/api/teste/erro', function (): never {
            throw new class('Algo conflitou.') extends ErroDeNegocio
            {
                public function status(): int
                {
                    return 409;
                }

                public function codigo(): string
                {
                    return 'CONFLITO_TESTE';
                }

                public function extras(): array
                {
                    return ['detalhe' => 1];
                }
            };
        });

        $this->getJson('/api/teste/erro')
            ->assertStatus(409)
            ->assertExactJson(['message' => 'Algo conflitou.', 'codigo' => 'CONFLITO_TESTE', 'detalhe' => 1]);
    }

    public function test_acesso_negado_tem_mensagem_padrao(): void
    {
        Route::get('/api/teste/negado', fn () => throw new AcessoNegadoException);

        $this->getJson('/api/teste/negado')
            ->assertForbidden()
            ->assertExactJson(['message' => 'Você não tem permissão para esta ação.', 'codigo' => 'ACESSO_NEGADO']);
    }
}

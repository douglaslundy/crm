<?php

declare(strict_types=1);

namespace Tests\Feature\Shared;

use App\Modules\Shared\Domain\Exceptions\ConsultaCepIndisponivelException;
use App\Modules\Shared\Infrastructure\ConsultaCep;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ConsultaCepTest extends TestCase
{
    public function test_consulta_cep_valido(): void
    {
        Http::fake(['*/ws/01001000/json/' => Http::response([
            'logradouro' => 'Praça da Sé', 'bairro' => 'Sé', 'localidade' => 'São Paulo', 'uf' => 'SP', 'ibge' => '3550308',
        ])]);

        $dados = app(ConsultaCep::class)->buscar('01001-000');

        $this->assertSame([
            'logradouro' => 'Praça da Sé', 'bairro' => 'Sé', 'cidade' => 'São Paulo', 'uf' => 'SP', 'codigo_ibge' => '3550308',
        ], $dados);
    }

    public function test_cep_inexistente_devolve_null(): void
    {
        Http::fake(['*' => Http::response(['erro' => true])]);

        $this->assertNull(app(ConsultaCep::class)->buscar('00000000'));
    }

    public function test_servico_fora_do_ar_lanca_excecao(): void
    {
        Http::fake(['*' => Http::failedConnection()]);

        $this->expectException(ConsultaCepIndisponivelException::class);

        app(ConsultaCep::class)->buscar('01001000');
    }

    public function test_resposta_e_cacheada_por_cep(): void
    {
        Http::fake(['*/ws/01001000/json/' => Http::response([
            'logradouro' => 'Praça da Sé', 'bairro' => 'Sé', 'localidade' => 'São Paulo', 'uf' => 'SP', 'ibge' => '3550308',
        ])]);

        app(ConsultaCep::class)->buscar('01001000');
        app(ConsultaCep::class)->buscar('01001000');

        Http::assertSentCount(1);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Unit\Fiscal;

use App\Modules\Fiscal\Domain\Emissao\ResultadoDeEpec;
use App\Modules\Fiscal\Domain\Exceptions\FalhaDeComunicacaoException;
use App\Modules\Fiscal\Domain\Models\Emitente;
use Carbon\CarbonImmutable;
use Tests\Fixtures\AssinaNfeDeTeste;
use Tests\Fixtures\EmissorFake;
use Tests\TestCase;

class EmissorFakeEpecTest extends TestCase
{
    use AssinaNfeDeTeste;

    public function test_prepara_com_chave_de_44_digitos_e_tpemis_4(): void
    {
        $emissor = new EmissorFake;

        $xml = $emissor->prepararEpec(Emitente::factory()->make(), $this->notaDeTeste(), 'Justificativa do EPEC de teste', CarbonImmutable::now());

        $this->assertSame(44, strlen($xml->chave));
        $this->assertSame('4', $xml->chave[34]);
        $this->assertSame(['prepararEpec'], $emissor->chamadas);
        $this->assertSame(['Justificativa do EPEC de teste'], $emissor->epecs);
    }

    public function test_registra_por_padrao_e_respeita_fila_e_falha(): void
    {
        $emissor = new EmissorFake;
        $emitente = Emitente::factory()->make();
        $nota = $this->notaDeTeste();
        $xml = $emissor->prepararEpec($emitente, $nota, 'Justificativa do EPEC de teste', CarbonImmutable::now());

        $this->assertTrue($emissor->registrarEpec($emitente, $nota, $xml)->registrado);

        $emissor->enfileirarEpec(ResultadoDeEpec::recusado('491', 'Dados diferem'));
        $this->assertFalse($emissor->registrarEpec($emitente, $nota, $xml)->registrado);

        $emissor->falharEmEpec = new FalhaDeComunicacaoException;
        $this->expectException(FalhaDeComunicacaoException::class);
        $emissor->registrarEpec($emitente, $nota, $xml);
    }
}

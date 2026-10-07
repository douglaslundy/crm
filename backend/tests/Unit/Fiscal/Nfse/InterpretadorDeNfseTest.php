<?php

declare(strict_types=1);

namespace Tests\Unit\Fiscal\Nfse;

use App\Modules\Fiscal\Domain\Enums\SituacaoNaSefaz;
use App\Modules\Fiscal\Domain\Enums\StatusNota;
use App\Modules\Fiscal\Infrastructure\Nfse\InterpretadorDeNfse;
use Nfse\Dto\Http\MensagemProcessamentoDto;
use Nfse\Dto\Http\RegistroEventoResponse;
use Nfse\Dto\Nfse\NfseData;
use Nfse\Http\Exceptions\NfseApiException;
use Nfse\Xml\NfseXmlParser;
use RuntimeException;
use Tests\TestCase;

class InterpretadorDeNfseTest extends TestCase
{
    private const CHAVE = '31305072212345678000195000000000000226096821694069';

    private function nfse(): NfseData
    {
        $xml = (string) file_get_contents(base_path('tests/Fixtures/nfse_nacional_autorizada.xml'));
        $dados = (new NfseXmlParser)->parse($xml);
        $dados->nfseXml = $xml;

        return $dados;
    }

    public function test_chave_de_50_digitos_sem_o_prefixo_nfs(): void
    {
        $i = new InterpretadorDeNfse;

        $this->assertSame(self::CHAVE, $i->chaveDe('NFS'.self::CHAVE));
        $this->assertSame(self::CHAVE, $i->chaveDe(self::CHAVE));
        $this->assertNull($i->chaveDe('NFS123'));
        $this->assertNull($i->chaveDe(null));
    }

    public function test_emissao_autorizada_devolve_chave_numero_e_xml(): void
    {
        $r = (new InterpretadorDeNfse)->emissao($this->nfse());

        $this->assertSame(StatusNota::Autorizada, $r->status);
        $this->assertSame(self::CHAVE, $r->chave);
        $this->assertSame(50, strlen((string) $r->chave));
        $this->assertSame('100', $r->cstat);
        $this->assertNotNull($r->numeroNfse);
        $this->assertStringContainsString('<NFSe', (string) $r->xmlAutorizado);
    }

    public function test_emissao_sem_xml_vira_erro_e_nunca_autorizada(): void
    {
        $semXml = $this->nfse();
        $semXml->nfseXml = null;

        $this->assertSame(StatusNota::Erro, (new InterpretadorDeNfse)->emissao($semXml)->status);
    }

    public function test_status_nao_reconhecido_vira_erro(): void
    {
        $dados = $this->nfse();
        $dados->infNfse->codigoStatus = null;

        $r = (new InterpretadorDeNfse)->emissao($dados);

        $this->assertSame(StatusNota::Erro, $r->status);
        $this->assertStringContainsString('status', (string) $r->mensagemErro);
    }

    public function test_consulta_nula_vira_erro_nunca_nao_encontrada(): void
    {
        $r = (new InterpretadorDeNfse)->consulta(null, false);

        $this->assertSame(SituacaoNaSefaz::Erro, $r->situacao);
    }

    public function test_consulta_autorizada_e_cancelada(): void
    {
        $i = new InterpretadorDeNfse;

        $autorizada = $i->consulta($this->nfse(), false);
        $this->assertSame(SituacaoNaSefaz::Autorizada, $autorizada->situacao);
        $this->assertSame(self::CHAVE, $autorizada->chave);

        $this->assertSame(SituacaoNaSefaz::Cancelada, $i->consulta($this->nfse(), true)->situacao);
    }

    public function test_rejeicao_so_quando_a_adn_devolveu_erros(): void
    {
        $i = new InterpretadorDeNfse;
        $erro = new MensagemProcessamentoDto(mensagem: 'IM não deve ser informado', codigo: 'E0120', descricao: 'IM do prestador');

        $r = $i->rejeicao(NfseApiException::requestError('HTTP 400', 400, '{}', [$erro]));

        $this->assertSame(StatusNota::Rejeitada, $r?->status);
        $this->assertSame('E0120', $r?->cstat);
        $this->assertStringContainsString('E0120', (string) $r?->motivo);
        $this->assertNull($i->rejeicao(NfseApiException::requestError('timeout', 0)));
    }

    public function test_evento_de_cancelamento_existe_inexistente_e_incerto(): void
    {
        $i = new InterpretadorDeNfse;

        $this->assertTrue($i->existeEventoDeCancelamento(fn () => 'ok'));
        $this->assertFalse($i->existeEventoDeCancelamento(fn () => throw NfseApiException::requestError('x', 404, '{"mensagem":"nenhum evento"}')));

        foreach ([[404, '<html>nao existe</html>'], [404, null], [500, '{"x":1}']] as [$codigo, $corpo]) {
            try {
                $i->existeEventoDeCancelamento(fn () => throw NfseApiException::requestError('x', $codigo, $corpo));
                $this->fail('Deveria propagar a incerteza.');
            } catch (NfseApiException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_cancelamento_exige_o_xml_do_evento(): void
    {
        $i = new InterpretadorDeNfse;
        $ok = $i->cancelamento(new RegistroEventoResponse(eventoXmlGZipB64: base64_encode((string) gzencode('<evento>x</evento>'))));

        $this->assertTrue($ok->aceito);
        $this->assertNull($ok->protocolo);
        $this->assertSame('101101', $ok->cstat);
        $this->assertSame('<evento>x</evento>', $ok->xmlCancelamento);

        $this->expectException(RuntimeException::class);
        $i->cancelamento(new RegistroEventoResponse(eventoXmlGZipB64: null));
    }

    public function test_classifica_o_motivo_do_cancelamento(): void
    {
        $i = new InterpretadorDeNfse;

        $this->assertSame('1', $i->codigoDoMotivo('Erro na emissão da nota'));
        $this->assertSame('2', $i->codigoDoMotivo('SERVIÇO NÃO PRESTADO ao cliente'));
        $this->assertSame('2', $i->codigoDoMotivo('servico nao realizado'));
        $this->assertSame('9', $i->codigoDoMotivo('O cliente desistiu da compra'));
    }
}

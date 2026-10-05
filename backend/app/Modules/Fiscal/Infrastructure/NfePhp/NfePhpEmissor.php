<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Infrastructure\NfePhp;

use App\Modules\Fiscal\Domain\Contracts\EmissorDeNfe;
use App\Modules\Fiscal\Domain\Emissao\NotaParaEmissao;
use App\Modules\Fiscal\Domain\Emissao\ResultadoDeCancelamento;
use App\Modules\Fiscal\Domain\Emissao\ResultadoDeConsulta;
use App\Modules\Fiscal\Domain\Emissao\ResultadoDeEmissao;
use App\Modules\Fiscal\Domain\Emissao\ResultadoDeInutilizacao;
use App\Modules\Fiscal\Domain\Emissao\XmlAssinado;
use App\Modules\Fiscal\Domain\Enums\ModeloDocumento;
use App\Modules\Fiscal\Domain\Exceptions\EmissaoBloqueadaException;
use App\Modules\Fiscal\Domain\Models\Emitente;
use App\Modules\Tenancy\Domain\Models\Tenant;
use Illuminate\Support\Facades\Crypt;
use NFePHP\NFe\Tools;

/**
 * Fala direto com a SEFAZ da UF do emitente, com o certificado A1 do próprio CNPJ.
 * Falha de comunicação (SoapException, timeout) propaga como exceção: o job a transforma em ERRO.
 * O modelo (55 ou 65) vem da nota; consulta e cancelamento o leem da própria chave.
 */
final class NfePhpEmissor implements EmissorDeNfe
{
    public function __construct(
        private readonly CertificadoStore $certificados,
        private readonly MontadorDeXml $montador,
        private readonly InterpretadorDeResposta $interpretador,
    ) {}

    public function preparar(Emitente $emitente, NotaParaEmissao $nota): XmlAssinado
    {
        ['xml' => $xml, 'chave' => $chave] = $this->montador->montar($nota);
        $assinado = $this->tools($emitente, $nota->emitente->razaoSocial, $nota->emitente->cnpj, $nota->ambiente, $this->numeroDoModelo($nota->modelo))->signNFe($xml);

        return new XmlAssinado($chave, $assinado);
    }

    public function transmitir(Emitente $emitente, NotaParaEmissao $nota, XmlAssinado $xml): ResultadoDeEmissao
    {
        $tools = $this->tools($emitente, $nota->emitente->razaoSocial, $nota->emitente->cnpj, $nota->ambiente, $this->numeroDoModelo($nota->modelo));
        $resposta = $tools->sefazEnviaLote([$xml->xml], (string) $nota->numero, 1);

        return $this->interpretador->emissao($xml->xml, $resposta);
    }

    public function consultarPorChave(Emitente $emitente, string $chave, ?string $xmlEnviado, string $ambiente): ResultadoDeConsulta
    {
        $tools = $this->toolsDoTenant($emitente, $ambiente, ModeloDaChave::de($chave));

        return $this->interpretador->consulta($tools->sefazConsultaChave($chave, $this->tpAmb($ambiente)), $xmlEnviado);
    }

    public function cancelar(Emitente $emitente, string $chave, string $protocolo, string $justificativa, string $ambiente, string $xmlAutorizado): ResultadoDeCancelamento
    {
        $tools = $this->toolsDoTenant($emitente, $ambiente, ModeloDaChave::de($chave));

        return $this->interpretador->cancelamento($tools->sefazCancela($chave, $justificativa, $protocolo), $xmlAutorizado);
    }

    public function inutilizar(Emitente $emitente, ModeloDocumento $modelo, int $serie, int $numeroInicial, int $numeroFinal, string $justificativa, string $ambiente): ResultadoDeInutilizacao
    {
        $tools = $this->toolsDoTenant($emitente, $ambiente, $this->numeroDoModelo($modelo));

        return $this->interpretador->inutilizacao(
            $tools->sefazInutiliza($serie, $numeroInicial, $numeroFinal, $justificativa, $this->tpAmb($ambiente)),
        );
    }

    private function numeroDoModelo(ModeloDocumento $modelo): int
    {
        return $modelo === ModeloDocumento::Nfce ? 65 : 55;
    }

    private function toolsDoTenant(Emitente $emitente, string $ambiente, int $modelo): Tools
    {
        $tenant = Tenant::query()->findOrFail($emitente->tenant_id);

        return $this->tools($emitente, $tenant->razao_social, preg_replace('/\D/', '', $tenant->cnpj) ?? '', $ambiente, $modelo);
    }

    private function tpAmb(string $ambiente): int
    {
        return $ambiente === 'PRODUCAO' ? 1 : 2;
    }

    private function tools(Emitente $emitente, string $razaoSocial, string $cnpj, string $ambiente, int $modelo): Tools
    {
        $config = [
            'atualizacao' => now()->format('Y-m-d H:i:s'),
            'tpAmb' => $this->tpAmb($ambiente),
            'razaosocial' => $razaoSocial,
            'siglaUF' => strtoupper((string) $emitente->uf),
            'cnpj' => $cnpj,
            'schemes' => 'PL_009_V4',
            'versao' => '4.00',
        ];
        // O QR Code da NFC-e é montado ao assinar e usa o CSC do ambiente; o token é decifrado só aqui, em memória.
        if ($modelo === 65) {
            $csc = $this->csc($emitente, $ambiente);
            $config['CSC'] = $csc['token'];
            $config['CSCid'] = $csc['id'];
        }

        $tools = new Tools(json_encode($config, JSON_THROW_ON_ERROR), $this->certificados->paraEmitente($emitente));
        $tools->model($modelo);

        return $tools;
    }

    /** @return array{id: string, token: string} */
    private function csc(Emitente $emitente, string $ambiente): array
    {
        $producao = $ambiente === 'PRODUCAO';
        $id = $producao ? $emitente->csc_id_producao : $emitente->csc_id_homologacao;
        $cifrado = $producao ? $emitente->csc_token_producao_encrypted : $emitente->csc_token_homologacao_encrypted;
        if ($id === null || trim($id) === '' || $cifrado === null || trim($cifrado) === '') {
            throw new EmissaoBloqueadaException('FISCAL_CSC_AUSENTE', 'Cadastre o CSC do ambiente em Configurações > Fiscal para emitir NFC-e.');
        }

        return ['id' => $id, 'token' => Crypt::decryptString($cifrado)];
    }
}

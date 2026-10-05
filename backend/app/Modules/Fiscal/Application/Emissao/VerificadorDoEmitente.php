<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Application\Emissao;

use App\Modules\Fiscal\Domain\Enums\CertificadoStatus;
use App\Modules\Fiscal\Domain\Enums\ModeloDocumento;
use App\Modules\Fiscal\Domain\Exceptions\EmissaoBloqueadaException;
use App\Modules\Fiscal\Domain\Models\Emitente;
use App\Modules\Fiscal\Domain\Resolvers\CrtResolver;
use App\Modules\Fiscal\Domain\TabelaIbge;
use App\Modules\Tenancy\Domain\Models\Tenant;

/** O emitente está apto a emitir NF-e? Cada falta vira um bloqueio que diz onde corrigir. */
final class VerificadorDoEmitente
{
    public function __construct(private readonly CrtResolver $crt) {}

    public function garantirApto(Emitente $emitente, Tenant $tenant, ModeloDocumento $modelo = ModeloDocumento::Nfe): void
    {
        $faltas = [];
        foreach ([
            'logradouro' => 'logradouro', 'numero' => 'número', 'bairro' => 'bairro', 'cidade' => 'cidade',
            'uf' => 'UF', 'cep' => 'CEP', 'codigo_ibge' => 'código IBGE do município', 'inscricao_estadual' => 'inscrição estadual',
        ] as $campo => $rotulo) {
            $valor = $emitente->getAttribute($campo);
            if ($valor === null || trim((string) $valor) === '') {
                $faltas[] = $rotulo;
            }
        }
        if ($faltas !== []) {
            throw new EmissaoBloqueadaException('FISCAL_EMITENTE_INCOMPLETO', 'Complete em Configurações > Fiscal: '.implode(', ', $faltas).'.');
        }

        $this->crt->crt($emitente->regime_tributario);
        TabelaIbge::codigoDaUf((string) $emitente->uf);

        $validade = $emitente->certificado_validade;
        if ($emitente->certificado_status !== CertificadoStatus::Valido || $validade === null || $validade->lt(now()->startOfDay())) {
            throw new EmissaoBloqueadaException('FISCAL_CERTIFICADO_INVALIDO', 'O certificado A1 está ausente, inválido ou vencido. Envie um certificado válido em Configurações > Fiscal.');
        }

        if (! $emitente->series()->where('modelo', $modelo)->exists()) {
            $rotulo = $modelo === ModeloDocumento::Nfce ? 'NFC-e' : 'NF-e';
            throw new EmissaoBloqueadaException('FISCAL_SERIE_NAO_CONFIGURADA', "Cadastre a série e o próximo número da {$rotulo} em Configurações > Fiscal.");
        }

        if (trim($tenant->cnpj) === '') {
            throw new EmissaoBloqueadaException('FISCAL_EMITENTE_INCOMPLETO', 'O CNPJ da empresa não está cadastrado.');
        }
    }

    /** A NFC-e leva o QR Code, que é assinado com o CSC do ambiente. Sem ele não há número alocado nem cota consumida. */
    public function garantirCsc(Emitente $emitente, string $ambiente): void
    {
        $producao = $ambiente === 'PRODUCAO';
        $id = $producao ? $emitente->csc_id_producao : $emitente->csc_id_homologacao;
        $token = $producao ? $emitente->csc_token_producao_encrypted : $emitente->csc_token_homologacao_encrypted;
        if ($id === null || trim($id) === '' || $token === null || trim($token) === '') {
            $nome = $producao ? 'produção' : 'homologação';
            throw new EmissaoBloqueadaException('FISCAL_CSC_AUSENTE', "Cadastre o CSC de {$nome} em Configurações > Fiscal para emitir NFC-e.");
        }
    }
}

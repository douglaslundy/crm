<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Infrastructure\Nfse;

use App\Modules\Fiscal\Domain\Emissao\NotaParaNfse;
use Nfse\Dto\Nfse\DpsData;
use Nfse\Support\IdGenerator;
use RuntimeException;

/**
 * Só traduz formato: tudo que chega aqui já foi resolvido e validado pelo `PreparadorDaNotaDeServico`.
 * As regras da ADN estão em `docs/referencia-fiscal/02-nfephp-nfse.md` (E0120, E0128, E0625, E0712, E1235).
 */
final class MontadorDeDps
{
    private const FUSO = 'America/Sao_Paulo';

    public static function idDaDps(NotaParaNfse $nota): string
    {
        return IdGenerator::generateDpsId($nota->emitente->cnpj, $nota->emitente->codigoIbge, $nota->serie, $nota->numeroDps);
    }

    public function montar(NotaParaNfse $nota): DpsData
    {
        $agora = now()->setTimezone(self::FUSO);
        $e = $nota->emitente;
        $regTrib = $this->regimeDoPrestador($e->regime);
        $tomador = [(strlen($nota->tomador->documento) > 11 ? 'CNPJ' : 'CPF') => $nota->tomador->documento, 'xNome' => $this->texto($nota->tomador->nome, 150)];

        $cServ = [
            // 6 dígitos (item + subitem + desdobro): "14.01" daria E1235.
            'cTribNac' => $nota->servico->cTribNac,
            // O código municipal é opcional e nunca se chuta: só vai com exatamente 3 dígitos.
            'cTribMun' => preg_match('/^\d{3}$/', (string) $nota->servico->cTribMun) === 1 ? $nota->servico->cTribMun : null,
            'xDescServ' => $this->texto($nota->servico->descricao, 2000),
        ];
        $serv = ['locPrest' => ['cLocPrestacao' => $e->codigoIbge], 'cServ' => array_filter($cServ, static fn ($v): bool => $v !== null)];
        $info = $nota->informacoesComplementares === null ? '' : $this->texto($nota->informacoesComplementares, 255);
        if ($info !== '') {
            $serv['infoCompl'] = ['xInfComp' => $info];
        }

        // A ordem das chaves é a do schema: vServPrest, vDescCondIncond, trib.
        $valores = ['vServPrest' => ['vServ' => $this->reais($nota->valorServicosCentavos)]];
        if ($nota->descontoCentavos > 0) {
            $valores['vDescCondIncond'] = ['vDescIncond' => $this->reais($nota->descontoCentavos)];
        }
        $valores['trib'] = [
            // tpRetISSQN 1 = não retido. No Simples não retido a alíquota é da própria ADN: informar `pAliq` dá E0625.
            'tribMun' => ['tribISSQN' => 1, 'tpRetISSQN' => 1],
            // ME/EPP e MEI usam pTotTribSN (indTotTrib dá E0712). 0.001 e não 0: a lib testa `if ($valor)` e some com 0.0;
            // ela formata com 2 casas, então vai "0.00" no XML.
            'totTrib' => ['pTotTribSN' => 0.001],
        ];

        return new DpsData([
            '@attributes' => ['versao' => '1.01'],
            'infDPS' => [
                '@attributes' => ['Id' => self::idDaDps($nota)],
                'tpAmb' => $nota->ambiente === 'PRODUCAO' ? 1 : 2,
                // ISO8601 com fuso, como o schema exige.
                'dhEmi' => $agora->format('c'),
                'verAplic' => (string) config('fiscal.ver_proc'),
                'serie' => $nota->serie,
                'nDPS' => (string) $nota->numeroDps,
                'dCompet' => $agora->format('Y-m-d'),
                'tpEmit' => 1,
                'cLocEmi' => $e->codigoIbge,
                // IM e endereço do prestador NUNCA vão: E0120 e E0128 (o prestador é o emitente, o cadastro está na ADN).
                'prest' => ['CNPJ' => $e->cnpj, 'regTrib' => $regTrib],
                'toma' => $tomador,
                'serv' => $serv,
                'valores' => $valores,
            ],
        ]);
    }

    /**
     * O `opSimpNac` da NFS-e (1 não optante, 2 MEI, 3 ME/EPP) NÃO é o CRT da NF-e: tradução explícita, nunca o valor bruto.
     *
     * @return array{opSimpNac: int, regApTribSN: int, regEspTrib: int}
     */
    private function regimeDoPrestador(string $regime): array
    {
        return match ($regime) {
            'MEI' => ['opSimpNac' => 2, 'regApTribSN' => 1, 'regEspTrib' => 0],
            'SIMPLES' => ['opSimpNac' => 3, 'regApTribSN' => 1, 'regEspTrib' => 0],
            default => throw new RuntimeException("Regime \"{$regime}\" sem tradução para a NFS-e: o Regime Normal fica bloqueado."),
        };
    }

    /** Dinheiro em centavos inteiros para reais (a lib formata com 2 casas). */
    private function reais(int $centavos): float
    {
        return round($centavos / 100, 2);
    }

    /** Sem caracteres de controle, espaços colapsados, corte no limite. O escape de `&` e `<` fica com a lib. */
    private function texto(string $valor, int $limite): string
    {
        $limpo = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $valor) ?? '';
        $limpo = preg_replace('/[\x{10000}-\x{10FFFF}]/u', '', $limpo) ?? '';
        $limpo = trim((string) preg_replace('/\s+/u', ' ', $limpo));

        return mb_substr($limpo, 0, $limite);
    }
}

<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Application\Emissao;

use App\Modules\Catalog\Domain\Models\Servico;
use App\Modules\Customers\Domain\Models\Cliente;
use App\Modules\Fiscal\Domain\Exceptions\EmissaoBloqueadaException;
use App\Modules\Fiscal\Domain\Models\Emitente;
use App\Modules\Fiscal\Domain\Models\Nota;
use App\Modules\Fiscal\Domain\RateioDeDesconto;

/**
 * Valida o que depende do tomador e dos serviços da NFS-e e congela o resultado na nota (snapshot fiscal).
 * Uma DPS leva um único serviço: todos os itens precisam ter o mesmo código de tributação e a mesma alíquota.
 */
final class PreparadorDaNotaDeServico
{
    public const LIMITE_DESCRICAO = 2000;

    public const LIMITE_INFO_COMPLEMENTAR = 255;

    public function preparar(Nota $nota, Emitente $emitente): void
    {
        $itens = $nota->itens()->get();
        if ($itens->isEmpty()) {
            throw new EmissaoBloqueadaException('FISCAL_NOTA_SEM_ITENS', 'Adicione ao menos um serviço à nota.');
        }

        $tomador = $this->tomador(Cliente::query()->findOrFail($nota->cliente_id));

        $info = $nota->informacoes_complementares;
        if ($info !== null && mb_strlen($info) > self::LIMITE_INFO_COMPLEMENTAR) {
            throw new EmissaoBloqueadaException('FISCAL_INFO_COMPLEMENTAR_LONGA', 'As informações complementares da NFS-e aceitam até '.self::LIMITE_INFO_COMPLEMENTAR.' caracteres.');
        }

        $totais = $itens->map(fn ($i): int => RateioDeDesconto::totalDoItem($i->quantidade_milesimos, $i->valor_unitario_centavos))->all();
        $descontos = RateioDeDesconto::ratear($nota->desconto_centavos, array_values($totais));
        $subtotal = array_sum($totais);
        $total = $subtotal - $nota->desconto_centavos;
        if ($total <= 0) {
            throw new EmissaoBloqueadaException('FISCAL_DESCONTO_INVALIDO', 'O total da nota deve ser maior que zero.');
        }

        $snapshots = [];
        foreach ($itens->values() as $i => $item) {
            $servico = Servico::query()->findOrFail($item->servico_id);
            $this->exigirServico($servico);
            $snapshots[$i] = [
                'descricao' => $servico->nome, 'unidade' => null,
                'c_trib_nac' => $servico->c_trib_nac, 'codigo_municipal' => $servico->codigo_municipal,
                'aliquota_iss' => $servico->aliquota_iss, 'nbs' => $servico->nbs,
                'desconto_centavos' => $descontos[$i], 'total_centavos' => $totais[$i],
            ];
        }

        $codigos = array_unique(array_column($snapshots, 'c_trib_nac'));
        $aliquotas = array_unique(array_map(fn (array $s): string => number_format((float) $s['aliquota_iss'], 2, '.', ''), $snapshots));
        if (count($codigos) > 1 || count($aliquotas) > 1) {
            throw new EmissaoBloqueadaException('FISCAL_SERVICOS_DIVERGENTES', 'Os serviços da nota têm códigos de tributação ou alíquotas diferentes. Emita uma NFS-e para cada código.');
        }

        $descricao = implode('; ', array_column($snapshots, 'descricao'));
        if (mb_strlen($descricao) > self::LIMITE_DESCRICAO) {
            throw new EmissaoBloqueadaException('FISCAL_DESCRICAO_LONGA', 'A descrição dos serviços passa de '.self::LIMITE_DESCRICAO.' caracteres. Emita menos serviços por nota.');
        }

        foreach ($itens->values() as $i => $item) {
            $item->update($snapshots[$i]);
        }
        $nota->forceFill(['subtotal_centavos' => $subtotal, 'total_centavos' => $total, 'destinatario' => $tomador])->save();
    }

    private function exigirServico(Servico $servico): void
    {
        $faltas = [];
        if ($servico->c_trib_nac === null || preg_match('/^\d{6}$/', $servico->c_trib_nac) !== 1) {
            $faltas[] = 'código de tributação nacional de 6 dígitos (ex.: 140101)';
        }
        if ($servico->aliquota_iss === null) {
            $faltas[] = 'alíquota do ISS';
        }
        if ($faltas !== []) {
            throw new EmissaoBloqueadaException('FISCAL_SERVICO_INCOMPLETO', "O serviço \"{$servico->nome}\" está sem: ".implode(', ', $faltas).'. Complete em Serviços.');
        }
    }

    /** @return array{tipo: string, documento: string, nome: string} */
    private function tomador(Cliente $c): array
    {
        $documento = $c->cpf_cnpj === null ? '' : (string) preg_replace('/\D/', '', $c->cpf_cnpj);
        if ($documento === '') {
            throw new EmissaoBloqueadaException('FISCAL_TOMADOR_INCOMPLETO', "O cliente \"{$c->nome}\" está sem: CPF/CNPJ. Complete em Clientes.");
        }

        return ['tipo' => $c->tipo->value, 'documento' => $documento, 'nome' => $c->nome];
    }
}

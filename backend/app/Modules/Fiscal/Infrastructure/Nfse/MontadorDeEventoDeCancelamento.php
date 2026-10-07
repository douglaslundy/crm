<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Infrastructure\Nfse;

use Nfse\Dto\Nfse\PedRegEventoData;

/** Pedido de registro do evento 101101 (cancelamento), versão 1.01. */
final class MontadorDeEventoDeCancelamento
{
    public function __construct(private readonly InterpretadorDeNfse $interpretador = new InterpretadorDeNfse) {}

    public function montar(string $chave, string $justificativa, string $ambiente, string $cnpjAutor): PedRegEventoData
    {
        $chave50 = preg_replace('/^NFS/', '', $chave) ?? $chave;

        return new PedRegEventoData([
            'versao' => '1.01',
            'infPedReg' => [
                'tpAmb' => $ambiente === 'PRODUCAO' ? 1 : 2,
                'verAplic' => (string) config('fiscal.ver_proc'),
                'dhEvento' => now()->setTimezone('America/Sao_Paulo')->format('c'),
                'chNFSe' => $chave50,
                'CNPJAutor' => $cnpjAutor,
                'tipoEvento' => '101101',
                'e101101' => [
                    'xDesc' => 'Cancelamento de NFS-e',
                    'cMotivo' => $this->interpretador->codigoDoMotivo($justificativa),
                    'xMotivo' => $justificativa,
                ],
            ],
        ]);
    }
}

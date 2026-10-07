<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Domain\Emissao;

use App\Modules\Fiscal\Domain\Models\Emitente;
use App\Modules\Fiscal\Domain\Models\Nota;
use App\Modules\Tenancy\Domain\Models\Tenant;
use LogicException;

/** Retrato imutável da nota de serviço, lido só do snapshot congelado. É o que o motor da NFS-e recebe. */
final readonly class NotaParaNfse
{
    public function __construct(
        public string $notaId,
        public string $ambiente,
        public string $serie,
        public int $numeroDps,
        public EmitenteParaNfse $emitente,
        public TomadorParaNfse $tomador,
        public ServicoParaNfse $servico,
        public int $valorServicosCentavos,
        public int $descontoCentavos,
        public ?string $informacoesComplementares,
    ) {}

    public static function deNota(Nota $nota, Emitente $emitente, Tenant $tenant): self
    {
        $d = $nota->destinatario;
        if ($d === null) {
            throw new LogicException('Nota sem snapshot do tomador: prepare antes de emitir.');
        }
        $itens = $nota->itens()->get();
        $primeiro = $itens->first();
        if ($primeiro === null || $primeiro->c_trib_nac === null || $primeiro->aliquota_iss === null) {
            throw new LogicException('Nota sem snapshot do serviço: prepare antes de emitir.');
        }
        $regime = $emitente->regime_tributario;
        if (! in_array($regime, ['SIMPLES', 'MEI'], true)) {
            throw new LogicException('Regime tributário sem tradução para a NFS-e.');
        }

        return new self(
            $nota->id, (string) $nota->ambiente, (string) $nota->serie, (int) $nota->numero,
            new EmitenteParaNfse(preg_replace('/\D/', '', $tenant->cnpj) ?? '', (string) $emitente->codigo_ibge, $regime),
            new TomadorParaNfse((string) $d['documento'], (string) $d['nome']),
            new ServicoParaNfse(
                $primeiro->c_trib_nac, $primeiro->codigo_municipal,
                implode('; ', $itens->pluck('descricao')->map(fn ($t): string => (string) $t)->all()),
                $primeiro->aliquota_iss, $primeiro->nbs,
            ),
            $nota->subtotal_centavos, $nota->desconto_centavos, $nota->informacoes_complementares,
        );
    }
}

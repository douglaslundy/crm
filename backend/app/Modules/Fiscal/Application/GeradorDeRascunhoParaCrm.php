<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Application;

use App\Modules\Crm\Domain\Contracts\GeradorDeRascunhoDeNota;
use App\Modules\Crm\Domain\LinhaDeProposta;
use App\Modules\Fiscal\Application\Actions\CriarRascunhoDeNota;
use App\Modules\Fiscal\Domain\Quantidade;
use App\Modules\Fiscal\Domain\RateioDeDesconto;
use App\Modules\Identity\Domain\Models\Usuario;
use App\Modules\Platform\Application\EntitlementService;
use App\Modules\Platform\Domain\Enums\Modulo;

/** Ponte CRM → Fiscal: usa as mesmas Actions do frontend; regra tributária continua toda no Fiscal. */
final class GeradorDeRascunhoParaCrm implements GeradorDeRascunhoDeNota
{
    public function __construct(
        private readonly CriarRascunhoDeNota $rascunhos,
        private readonly EntitlementService $planos,
    ) {}

    public function disponivel(Usuario $autor): bool
    {
        return $autor->tenant !== null
            && ($this->planos->temModulo($autor->tenant, Modulo::FiscalNfe) || $this->planos->temModulo($autor->tenant, Modulo::FiscalNfse));
    }

    public function gerar(Usuario $autor, string $clienteId, array $linhas, int $descontoCentavos): array
    {
        /** @var array<string, list<LinhaDeProposta>> $grupos modelo => linhas */
        $grupos = [];
        foreach (['PRODUTO' => 'NFE', 'SERVICO' => 'DPS'] as $tipo => $modelo) {
            $doTipo = array_values(array_filter($linhas, fn (LinhaDeProposta $l): bool => $l->tipo->value === $tipo));
            if ($doTipo !== []) {
                $grupos[$modelo] = $doTipo;
            }
        }

        $totais = array_values(array_map(
            fn (array $grupo): int => array_sum(array_map(
                fn (LinhaDeProposta $l): int => RateioDeDesconto::totalDoItem(Quantidade::paraMilesimos((string) $l->quantidade), $l->valorUnitarioCentavos),
                $grupo,
            )),
            $grupos,
        ));
        $descontos = RateioDeDesconto::ratear($descontoCentavos, $totais);

        $ids = [];
        foreach (array_keys($grupos) as $i => $modelo) {
            $chave = $modelo === 'DPS' ? 'servico_id' : 'produto_id';
            $ids[] = $this->rascunhos->executar([
                'modelo' => $modelo,
                'cliente_id' => $clienteId,
                'desconto_centavos' => $descontos[$i],
                'itens' => array_map(fn (LinhaDeProposta $l): array => [
                    $chave => $l->itemId,
                    'quantidade' => (string) $l->quantidade,
                    'valor_unitario_centavos' => $l->valorUnitarioCentavos,
                ], $grupos[$modelo]),
                'pagamentos' => [],
            ], $autor)->id;
        }

        return $ids;
    }
}

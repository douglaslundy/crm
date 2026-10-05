<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Application\Actions;

use App\Modules\Catalog\Domain\Models\Produto;
use App\Modules\Fiscal\Application\PoliticaDeNotas;
use App\Modules\Fiscal\Domain\Enums\ModeloDocumento;
use App\Modules\Fiscal\Domain\Enums\StatusNota;
use App\Modules\Fiscal\Domain\Models\Emitente;
use App\Modules\Fiscal\Domain\Models\Nota;
use App\Modules\Fiscal\Domain\Models\NotaEvento;
use App\Modules\Fiscal\Domain\Quantidade;
use App\Modules\Fiscal\Domain\RateioDeDesconto;
use App\Modules\Identity\Domain\Models\Usuario;
use App\Modules\Platform\Application\EntitlementService;
use App\Modules\Platform\Domain\Enums\Modulo;
use Illuminate\Support\Facades\DB;

final class CriarRascunhoDeNota
{
    public function __construct(private readonly PoliticaDeNotas $politica, private readonly EntitlementService $planos) {}

    /** @param array<string, mixed> $dados */
    public function executar(array $dados, Usuario $autor): Nota
    {
        $this->politica->garantirPodeRascunhar($autor);
        $modelo = ModeloDocumento::from((string) ($dados['modelo'] ?? 'NFE'));
        if ($modelo === ModeloDocumento::Nfce) {
            $this->planos->garantirModulo($autor->tenant, Modulo::FiscalNfce);
        }

        return DB::transaction(function () use ($dados, $autor, $modelo): Nota {
            $emitente = Emitente::query()->firstOrCreate([], []);
            $nota = new Nota;
            $nota->emitente_id = $emitente->id;
            $nota->modelo = $modelo;
            $nota->cliente_id = $dados['cliente_id'] ?? null;
            $nota->save();
            $this->preencher($nota, $dados);
            NotaEvento::registrar($nota, null, StatusNota::Rascunho, 'Rascunho criado', $autor->id);

            return $nota->refresh();
        });
    }

    /**
     * Grava cabeçalho, itens e pagamentos (substitui os existentes) e recalcula os totais.
     *
     * @param  array<string, mixed>  $dados
     */
    public function preencher(Nota $nota, array $dados): void
    {
        $itens = [];
        $subtotal = 0;
        foreach (array_values($dados['itens']) as $i => $linha) {
            $produto = Produto::query()->findOrFail($linha['produto_id']);
            $milesimos = Quantidade::paraMilesimos((string) $linha['quantidade']);
            $unitario = $linha['valor_unitario_centavos'] ?? $produto->preco_centavos;
            $total = RateioDeDesconto::totalDoItem($milesimos, $unitario);
            $subtotal += $total;
            $itens[] = [
                'produto_id' => $produto->id, 'ordem' => $i + 1, 'quantidade_milesimos' => $milesimos,
                'valor_unitario_centavos' => $unitario, 'total_centavos' => $total,
            ];
        }

        $desconto = (int) $dados['desconto_centavos'];
        $nota->fill([
            'cliente_id' => $dados['cliente_id'] ?? null,
            'consumidor_final' => $dados['consumidor_final'] ?? null,
            'desconto_centavos' => $desconto,
            'informacoes_complementares' => $dados['informacoes_complementares'] ?? null,
            'subtotal_centavos' => $subtotal,
            'total_centavos' => $subtotal - $desconto,
        ])->save();

        $nota->itens()->delete();
        $nota->itens()->createMany($itens);
        $nota->pagamentos()->delete();
        $nota->pagamentos()->createMany(array_map(fn (array $p): array => [
            'tpag' => $p['tpag'], 'xpag' => $p['xpag'] ?? null, 'valor_centavos' => (int) $p['valor_centavos'],
        ], array_values($dados['pagamentos'])));
    }
}

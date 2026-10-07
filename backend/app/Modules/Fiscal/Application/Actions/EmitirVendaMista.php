<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Application\Actions;

use App\Modules\Fiscal\Application\PoliticaDeNotas;
use App\Modules\Fiscal\Application\Vendas\ResultadoDaVenda;
use App\Modules\Fiscal\Domain\Exceptions\VendaComNotaBloqueadaException;
use App\Modules\Fiscal\Domain\Models\Nota;
use App\Modules\Fiscal\Domain\Models\Venda;
use App\Modules\Identity\Domain\Models\Usuario;
use App\Modules\Shared\Domain\Exceptions\ErroDeNegocio;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Emite a NF-e e a NFS-e da venda, cada uma pelo fluxo normal de emissão. Antes de emitir, confere as duas sem efeito
 * colateral: com uma pendente, nada sai a menos que o usuário confirme emitir só a válida. Nota autorizada nunca é
 * desfeita por causa da outra.
 */
final class EmitirVendaMista
{
    public function __construct(private readonly PoliticaDeNotas $politica, private readonly IniciarEmissao $emissao) {}

    public function executar(Venda $venda, Usuario $autor, bool $emitirApenasValidas = false): ResultadoDaVenda
    {
        $this->politica->garantirPodeEmitir($autor);
        $notas = Nota::query()->where('venda_id', $venda->id)->get();

        /** @var array<string, array{codigo: string, mensagem: string}> $bloqueios */
        $bloqueios = [];
        foreach ($notas as $nota) {
            if ($this->jaEmitida($nota)) {
                continue;
            }
            try {
                $this->emissao->verificar($nota, $autor);
            } catch (ErroDeNegocio $e) {
                $bloqueios[$nota->id] = ['codigo' => $e->codigo(), 'mensagem' => $e->getMessage()];
            }
        }

        $emitiveis = $notas->reject(fn (Nota $n): bool => $this->jaEmitida($n) || isset($bloqueios[$n->id]));
        if ($bloqueios !== [] && (! $emitirApenasValidas || $emitiveis->isEmpty())) {
            throw new VendaComNotaBloqueadaException($this->resultado($notas, $bloqueios));
        }

        foreach ($emitiveis as $nota) {
            try {
                $this->emissao->executar($nota->id, $autor);
            } catch (ErroDeNegocio $e) {
                $bloqueios[$nota->id] = ['codigo' => $e->codigo(), 'mensagem' => $e->getMessage()];
            } catch (Throwable $e) {
                report($e);
                $bloqueios[$nota->id] = ['codigo' => 'FISCAL_VENDA_FALHA_AO_EMITIR', 'mensagem' => 'Não foi possível iniciar a emissão desta nota. Tente de novo.'];
            }
        }

        return $this->resultado(Nota::query()->where('venda_id', $venda->id)->get(), $bloqueios);
    }

    /** Retentativa: a nota que já saiu do rascunho não é verificada nem emitida de novo. */
    private function jaEmitida(Nota $nota): bool
    {
        return ! $nota->status->editavel();
    }

    /**
     * @param  Collection<int, Nota>  $notas
     * @param  array<string, array{codigo: string, mensagem: string}>  $bloqueios
     */
    private function resultado(Collection $notas, array $bloqueios): ResultadoDaVenda
    {
        return new ResultadoDaVenda($notas->sortBy(fn (Nota $n): string => $n->modelo->value)->values()->map(fn (Nota $n): array => [
            'nota_id' => $n->id, 'modelo' => $n->modelo->value, 'status' => $n->status->value, 'bloqueio' => $bloqueios[$n->id] ?? null,
        ])->all());
    }
}

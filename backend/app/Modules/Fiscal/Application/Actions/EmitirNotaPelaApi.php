<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Application\Actions;

use App\Modules\Fiscal\Application\ResolvedorDeCadastrosInline;
use App\Modules\Fiscal\Domain\Enums\AmbienteFiscal;
use App\Modules\Fiscal\Domain\Exceptions\ApiAmbienteDivergenteException;
use App\Modules\Fiscal\Domain\Models\Emitente;
use App\Modules\Fiscal\Domain\Models\Nota;
use App\Modules\Identity\Domain\Enums\AmbienteDaChave;
use App\Modules\Identity\Domain\Models\Usuario;
use App\Modules\Shared\Domain\Exceptions\ErroDeNegocio;
use Illuminate\Support\Facades\DB;

/**
 * Cria e emite uma nota a partir do corpo da API (itens e cliente por referência ou inline). Só compõe as Actions
 * do app: nenhuma regra fiscal mora aqui. Qualquer bloqueio antes do commit desfaz rascunho e cadastros inline.
 */
final class EmitirNotaPelaApi
{
    public function __construct(
        private readonly ResolvedorDeCadastrosInline $resolvedor,
        private readonly CriarRascunhoDeNota $criarRascunho,
        private readonly IniciarEmissao $iniciarEmissao,
        private readonly ExcluirRascunhoDeNota $excluirRascunho,
    ) {}

    /** @param array<string, mixed> $dados corpo já validado pelo EmitirNotaApiRequest */
    public function executar(Usuario $autor, AmbienteDaChave $ambiente, array $dados): Nota
    {
        $this->garantirAmbiente($ambiente);

        $nota = DB::transaction(function () use ($autor, $dados): Nota {
            $nota = $this->criarRascunho->executar($this->corpoDoApp($autor, $dados), $autor);
            $this->iniciarEmissao->verificar($nota, $autor);

            return $nota;
        });

        // Depois do commit, para o job nunca rodar antes de a nota existir.
        try {
            return $this->iniciarEmissao->executar($nota->id, $autor);
        } catch (ErroDeNegocio $e) {
            $this->excluirRascunho->executar($nota->refresh());

            throw $e;
        }
    }

    private function garantirAmbiente(AmbienteDaChave $ambiente): void
    {
        $emitente = Emitente::query()->first();
        if ($emitente === null) {
            return; // o bloqueio de emitente incompleto vem da verificação da emissão, com o código do app
        }

        $casa = ($ambiente === AmbienteDaChave::Test) === ($emitente->ambiente_fiscal === AmbienteFiscal::Homologacao);
        if (! $casa) {
            throw new ApiAmbienteDivergenteException($ambiente, $emitente->ambiente_fiscal);
        }
    }

    /**
     * @param  array<string, mixed>  $dados
     * @return array<string, mixed>
     */
    private function corpoDoApp(Usuario $autor, array $dados): array
    {
        $servico = ($dados['modelo'] ?? 'NFE') === 'DPS';

        $clienteId = null;
        if (is_array($dados['cliente'] ?? null)) {
            $cliente = $dados['cliente'];
            $clienteId = isset($cliente['id']) ? (string) $cliente['id'] : $this->resolvedor->cliente($autor, $cliente)->id;
        }

        $itens = [];
        foreach (array_values($dados['itens']) as $i => $item) {
            $linha = ['quantidade' => $item['quantidade'], 'valor_unitario_centavos' => $item['valor_unitario_centavos'] ?? null];
            if ($servico) {
                $linha['servico_id'] = isset($item['servico_id']) ? (string) $item['servico_id'] : $this->resolvedor->servico($autor, $item['servico'], "itens.{$i}.servico")->id;
            } else {
                $linha['produto_id'] = isset($item['produto_id']) ? (string) $item['produto_id'] : $this->resolvedor->produto($autor, $item['produto'], "itens.{$i}.produto")->id;
            }
            $itens[] = $linha;
        }

        return [
            'modelo' => $dados['modelo'] ?? 'NFE',
            'cliente_id' => $clienteId,
            'consumidor_final' => $dados['consumidor_final'] ?? null,
            'desconto_centavos' => (int) $dados['desconto_centavos'],
            'informacoes_complementares' => $dados['informacoes_complementares'] ?? null,
            'itens' => $itens,
            'pagamentos' => $servico ? [] : array_values($dados['pagamentos'] ?? []),
        ];
    }
}

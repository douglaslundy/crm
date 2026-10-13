<?php

declare(strict_types=1);

namespace App\Modules\Crm\Application\Actions;

use App\Modules\Crm\Application\PoliticaDoCrm;
use App\Modules\Crm\Application\ValidadorDeProposta;
use App\Modules\Crm\Domain\Enums\StatusDaProposta;
use App\Modules\Crm\Domain\Exceptions\RegraDoCrmException;
use App\Modules\Crm\Domain\Models\Negocio;
use App\Modules\Crm\Domain\Models\Proposta;
use App\Modules\Identity\Domain\Models\Usuario;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

final class CriarProposta
{
    private const TENTATIVAS = 3;

    public function __construct(private readonly PoliticaDoCrm $politica) {}

    /** @param array{validade_dias?: int, desconto_centavos?: int, observacoes?: ?string} $dados */
    public function executar(Usuario $autor, Negocio $negocio, array $dados = []): Proposta
    {
        $this->politica->garantirPodeOperarNegocios($autor);

        for ($tentativa = 1; ; $tentativa++) {
            try {
                return DB::transaction(fn (): Proposta => $this->criar($autor, $negocio, $dados));
            } catch (UniqueConstraintViolationException $e) {
                // Duas propostas pegaram o mesmo número ao mesmo tempo: relê o máximo e tenta de novo.
                if ($tentativa >= self::TENTATIVAS) {
                    throw $e;
                }
            }
        }
    }

    /** @param array{validade_dias?: int, desconto_centavos?: int, observacoes?: ?string} $dados */
    private function criar(Usuario $autor, Negocio $negocio, array $dados): Proposta
    {
        $negocio = Negocio::query()->with('itens')->lockForUpdate()->findOrFail($negocio->id);
        $negocio->garantirAberto();
        if ($negocio->itens->isEmpty()) {
            throw new RegraDoCrmException('Adicione ao menos um item ao negócio antes de criar a proposta.', 'NEGOCIO_SEM_ITENS');
        }

        $proposta = Proposta::create([
            'negocio_id' => $negocio->id,
            'cliente_id' => $negocio->cliente_id,
            'numero' => (int) Proposta::query()->max('numero') + 1,
            'status' => StatusDaProposta::Rascunho,
            'validade_dias' => $dados['validade_dias'] ?? 15,
            'desconto_centavos' => $dados['desconto_centavos'] ?? 0,
            'observacoes' => $dados['observacoes'] ?? null,
            'criado_por' => $autor->id,
        ]);
        foreach ($negocio->itens as $i => $item) {
            $proposta->itens()->create([
                'tipo' => $item->tipo, 'item_id' => $item->item_id, 'descricao' => $item->descricao,
                'quantidade' => $item->quantidade, 'valor_unitario_centavos' => $item->valor_unitario_centavos, 'ordem' => $i + 1,
            ]);
        }
        ValidadorDeProposta::garantirDescontoValido($proposta);

        return $proposta;
    }
}

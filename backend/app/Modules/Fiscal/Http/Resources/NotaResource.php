<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Http\Resources;

use App\Modules\Fiscal\Domain\Models\Nota;
use App\Modules\Fiscal\Domain\Quantidade;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Nota */
final class NotaResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'modelo' => $this->modelo->value,
            'status' => $this->status->value,
            'ambiente' => $this->ambiente,
            'numero' => $this->numero,
            'serie' => $this->serie,
            'chave' => $this->chave,
            'protocolo' => $this->protocolo,
            'cstat' => $this->cstat,
            'motivo' => $this->motivo,
            'mensagem_erro' => $this->mensagem_erro,
            'natureza_operacao' => $this->natureza_operacao,
            'consumidor_final' => $this->consumidor_final,
            'subtotal_centavos' => $this->subtotal_centavos,
            'desconto_centavos' => $this->desconto_centavos,
            'total_centavos' => $this->total_centavos,
            'informacoes_complementares' => $this->informacoes_complementares,
            'emitido_em' => $this->emitido_em?->toIso8601String(),
            'contingencia_limite_em' => $this->contingencia_limite_em?->toIso8601String(),
            'contingencia_motivo' => $this->contingencia_motivo,
            'contingencia_tipo' => $this->contingencia_tipo?->value,
            'epec_protocolo' => $this->epec_protocolo,
            'epec_registrado_em' => $this->epec_registrado_em?->toIso8601String(),
            'cancelamento_justificativa' => $this->cancelamento_justificativa,
            'cancelado_em' => $this->cancelado_em?->toIso8601String(),
            'cancelamento_protocolo' => $this->cancelamento_protocolo,
            'cancelamento_cstat' => $this->cancelamento_cstat,
            'cancelamento_motivo' => $this->cancelamento_motivo,
            'created_at' => $this->created_at->toIso8601String(),
            'cliente' => $this->whenLoaded('cliente', fn (): ?array => $this->cliente === null ? null : ['id' => $this->cliente->id, 'nome' => $this->cliente->nome]),
            'itens' => $this->whenLoaded('itens', fn () => $this->itens->map(fn ($i): array => [
                'id' => $i->id, 'produto_id' => $i->produto_id, 'ordem' => $i->ordem, 'sku' => $i->sku, 'descricao' => $i->descricao,
                'unidade' => $i->unidade, 'quantidade' => Quantidade::formatar($i->quantidade_milesimos, 3),
                'valor_unitario_centavos' => $i->valor_unitario_centavos, 'desconto_centavos' => $i->desconto_centavos, 'total_centavos' => $i->total_centavos,
                'cfop' => $i->cfop, 'csosn' => $i->csosn,
            ])->all()),
            'pagamentos' => $this->whenLoaded('pagamentos', fn () => $this->pagamentos->map(fn ($p): array => [
                'tpag' => $p->tpag, 'xpag' => $p->xpag, 'valor_centavos' => $p->valor_centavos,
            ])->all()),
            'eventos' => $this->whenLoaded('eventos', fn () => $this->eventos->map(fn ($e): array => [
                'status_de' => $e->status_de, 'status_para' => $e->status_para, 'detalhe' => $e->detalhe, 'em' => $e->created_at->toIso8601String(),
            ])->all()),
        ];
    }
}

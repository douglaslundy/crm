<?php

declare(strict_types=1);

namespace App\Modules\Billing\Http\Resources;

use App\Modules\Billing\Domain\Models\Cobranca;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Cobranca */
final class CobrancaResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'tipo' => $this->tipo->value,
            'valor_centavos' => $this->valor_centavos,
            'vence_em' => $this->vence_em->toDateString(),
            'status' => $this->status->value,
            'metodo' => $this->metodo?->value,
            'boleto_url' => $this->boleto_url,
            'boleto_linha_digitavel' => $this->boleto_linha_digitavel,
            'pix_qr_code' => $this->pix_qr_code,
            'pix_qr_code_base64' => $this->pix_qr_code_base64,
            'pix_expira_em' => $this->pix_expira_em?->toIso8601String(),
            'mes_referencia' => $this->mes_referencia?->toDateString(),
            'documentos_excedentes' => $this->documentos_excedentes,
            'valor_unitario_centavos' => $this->valor_unitario_centavos,
            'plano_destino_id' => $this->plano_destino_id,
            'pago_em' => $this->pago_em?->toIso8601String(),
        ];
    }
}

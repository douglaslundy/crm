<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Http\Resources;

use App\Modules\Fiscal\Domain\Models\Emitente;
use App\Modules\Platform\Application\EntitlementService;
use App\Modules\Platform\Domain\Enums\Modulo;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Emitente */
final class EmitenteResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'logradouro' => $this->logradouro,
            'numero' => $this->numero,
            'bairro' => $this->bairro,
            'cidade' => $this->cidade,
            'uf' => $this->uf,
            'cep' => $this->cep,
            'codigo_ibge' => $this->codigo_ibge,
            'regime_tributario' => $this->regime_tributario,
            'inscricao_estadual' => $this->inscricao_estadual,
            'inscricao_municipal' => $this->inscricao_municipal,
            'cnae' => $this->cnae,
            'ambiente_fiscal' => $this->ambiente_fiscal->value,
            'certificado_status' => $this->certificado_status->value,
            'certificado_validade' => $this->certificado_validade?->toDateString(),
            'certificado_titular' => $this->certificado_titular,
            'csc_homologacao_configurado' => $this->csc_id_homologacao !== null,
            'csc_producao_configurado' => $this->csc_id_producao !== null,
            'exige_csc' => app(EntitlementService::class)->temModulo($this->tenant, Modulo::FiscalNfce),
            'series' => EmitenteSerieResource::collection($this->whenLoaded('series')),
        ];
    }
}

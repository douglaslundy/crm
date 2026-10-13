<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Modules\Crm\Application\Actions\CriarProposta;
use App\Modules\Crm\Application\Actions\EnviarProposta;
use App\Modules\Crm\Domain\Models\Etapa;
use App\Modules\Crm\Domain\Models\Funil;
use App\Modules\Crm\Domain\Models\Negocio;
use App\Modules\Crm\Domain\Models\Proposta;
use Illuminate\Support\Str;

/** Exige `ComCrm` na mesma classe de teste (usa `$this->admin`). */
trait ComPropostas
{
    protected function negocioComItens(int $quantidade = 2, int $unitario = 1500, string $tipo = 'PRODUTO', ?string $itemId = null): Negocio
    {
        $funil = Funil::factory()->padrao()->create();
        $etapa = Etapa::factory()->create(['funil_id' => $funil->id, 'posicao' => 0]);
        $negocio = Negocio::factory()->create([
            'funil_id' => $funil->id, 'etapa_id' => $etapa->id, 'responsavel_id' => $this->admin->id,
        ]);
        $negocio->itens()->create([
            'tipo' => $tipo, 'item_id' => $itemId ?? (string) Str::uuid(), 'descricao' => 'Item A',
            'quantidade' => $quantidade, 'valor_unitario_centavos' => $unitario,
        ]);

        return $negocio->refresh()->load('itens');
    }

    protected function propostaEnviada(?Negocio $negocio = null): Proposta
    {
        $proposta = app(CriarProposta::class)->executar($this->admin, $negocio ?? $this->negocioComItens());

        return app(EnviarProposta::class)->executar($this->admin, $proposta);
    }
}

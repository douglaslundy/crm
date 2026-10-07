<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use App\Modules\Catalog\Domain\Models\Servico;
use App\Modules\Fiscal\Application\Emissao\PreparadorDaNotaDeServico;
use App\Modules\Fiscal\Domain\Enums\ModeloDocumento;
use App\Modules\Fiscal\Domain\Enums\StatusNota;
use App\Modules\Fiscal\Domain\Models\EmitenteSerie;
use App\Modules\Fiscal\Domain\Models\Nota;
use App\Modules\Platform\Domain\Enums\Modulo;
use App\Modules\Platform\Domain\Models\Plano;

/** Cenário da NF-e mais o módulo NFS-e, a série DPS e um serviço completo (Simples, SP, tomador PF). */
trait CenarioDeNfse
{
    use CenarioDeNota;

    protected Servico $servico;

    protected function montarCenarioNfse(Modulo ...$extras): void
    {
        $this->montarCenario(Modulo::FiscalNfse, ...$extras);
        EmitenteSerie::factory()->for($this->emitente)->create(['modelo' => ModeloDocumento::Dps, 'serie' => '1', 'proximo_numero' => 1]);
        $this->servico = Servico::factory()->completo()->create(['nome' => 'Troca de óleo', 'preco_centavos' => 15000]);
    }

    /**
     * @param  array<string, mixed>  $sobrescrever
     * @return array<string, mixed>
     */
    protected function corpoNfse(array $sobrescrever = []): array
    {
        return array_replace([
            'modelo' => 'DPS', 'cliente_id' => $this->cliente->id, 'consumidor_final' => null, 'desconto_centavos' => 0,
            'informacoes_complementares' => null,
            'itens' => [['servico_id' => $this->servico->id, 'quantidade' => '1', 'valor_unitario_centavos' => null]],
        ], $sobrescrever);
    }

    /** @param  array<string, mixed>  $sobrescrever */
    protected function rascunhoDeNfse(array $sobrescrever = []): Nota
    {
        $id = $this->spaComo($this->admin)->postJson('/api/app/notas', $this->corpoNfse($sobrescrever))->assertCreated()->json('data.id');

        return Nota::query()->findOrFail($id);
    }

    /**
     * Rascunho preparado (snapshot do tomador e do serviço) já no status pedido, com número alocado.
     *
     * @param  array<string, mixed>  $campos
     */
    protected function notaDeNfsePronta(StatusNota $status, array $campos = []): Nota
    {
        $nota = $this->rascunhoDeNfse();
        app(PreparadorDaNotaDeServico::class)->preparar($nota, $this->emitente);
        $nota->forceFill([
            'status' => $status, 'numero' => 1, 'serie' => '1', 'ambiente' => 'HOMOLOGACAO', 'iniciada_em' => now(),
        ] + $campos)->save();

        return $nota->refresh();
    }

    /** Troca o plano do tenant por um que só tem NF-e (`plano_id` não é fillable: só por forceFill). */
    protected function removerModuloNfse(): void
    {
        $this->tenant->forceFill(['plano_id' => Plano::factory()->comModulos(Modulo::FiscalNfe)->create()->id])->save();
        // O usuário do teste guarda o tenant (e o plano) em cache: sem isto a requisição seguinte ainda enxerga o plano antigo.
        $this->tenant->unsetRelation('plano');
        $this->admin->unsetRelation('tenant');
    }
}

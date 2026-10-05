<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Application\Actions;

use App\Modules\Fiscal\Application\Jobs\CancelarNotaJob;
use App\Modules\Fiscal\Application\PoliticaDeNotas;
use App\Modules\Fiscal\Domain\Enums\ModeloDocumento;
use App\Modules\Fiscal\Domain\Enums\StatusNota;
use App\Modules\Fiscal\Domain\Exceptions\CancelamentoNaoPermitidoException;
use App\Modules\Fiscal\Domain\Justificativa;
use App\Modules\Fiscal\Domain\Models\Nota;
use App\Modules\Fiscal\Domain\Models\NotaEvento;
use App\Modules\Identity\Domain\Models\Usuario;
use App\Modules\Platform\Application\EntitlementService;
use App\Modules\Platform\Domain\Enums\Modulo;
use App\Modules\Platform\Domain\Exceptions\AssinaturaSemEscritaException;
use Illuminate\Support\Facades\DB;

final class IniciarCancelamento
{
    public function __construct(
        private readonly PoliticaDeNotas $politica,
        private readonly EntitlementService $planos,
    ) {}

    public function executar(string $notaId, string $justificativa, Usuario $autor): Nota
    {
        $this->politica->garantirPodeEmitir($autor);
        $texto = Justificativa::de($justificativa)->texto;

        $resultado = DB::transaction(function () use ($notaId, $texto, $autor): array {
            $nota = Nota::query()->whereKey($notaId)->lockForUpdate()->firstOrFail();

            if (in_array($nota->status, [StatusNota::Cancelando, StatusNota::Cancelada], true)) {
                return [$nota, false];
            }

            $tenant = $autor->tenant;
            if (! $tenant->situacao->permiteEscrita()) {
                throw AssinaturaSemEscritaException::para($tenant->situacao);
            }
            $nfce = $nota->modelo === ModeloDocumento::Nfce;
            $this->planos->garantirModulo($tenant, $nfce ? Modulo::FiscalNfce : Modulo::FiscalNfe);

            if ($nota->status !== StatusNota::Autorizada) {
                throw new CancelamentoNaoPermitidoException('Só é possível cancelar uma '.($nfce ? 'NFC-e' : 'NF-e').' autorizada. Status atual: '.$nota->status->value.'.');
            }
            $nota->makeVisible('xml_autorizado');
            if ($nota->chave === null || $nota->protocolo === null || $nota->xml_autorizado === null) {
                throw new CancelamentoNaoPermitidoException('A nota não tem chave, protocolo ou XML autorizado: concilie-a com a SEFAZ antes de cancelar.');
            }

            $nota->forceFill([
                'status' => StatusNota::Cancelando, 'cancelamento_solicitado_em' => now(), 'cancelamento_justificativa' => $texto,
                'cancelamento_cstat' => null, 'cancelamento_motivo' => null,
            ])->save();
            NotaEvento::registrar($nota, StatusNota::Autorizada, StatusNota::Cancelando, 'Cancelamento solicitado: '.$texto, $autor->id);

            return [$nota, true];
        });

        [$nota, $despachar] = $resultado;
        if ($despachar) {
            CancelarNotaJob::dispatch($nota->id, $nota->tenant_id);
        }

        return $nota->refresh();
    }
}

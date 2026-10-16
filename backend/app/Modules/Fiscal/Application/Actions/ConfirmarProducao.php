<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Application\Actions;

use App\Modules\Fiscal\Domain\Enums\AmbienteFiscal;
use App\Modules\Fiscal\Domain\Enums\StatusNota;
use App\Modules\Fiscal\Domain\Exceptions\EmissaoBloqueadaException;
use App\Modules\Fiscal\Domain\Models\Emitente;
use App\Modules\Fiscal\Domain\Models\EmitenteSerie;
use App\Modules\Fiscal\Domain\Models\Nota;
use App\Modules\Identity\Domain\Models\Usuario;
use Illuminate\Support\Facades\DB;

final class ConfirmarProducao
{
    /** @param array<string, int> $numerosIniciais primeiro número de produção por modelo (valor do enum); ausente = 1 */
    public function executar(Emitente $emitente, Usuario $autor, array $numerosIniciais = []): Emitente
    {
        $temTeste = Nota::query()->where('status', StatusNota::Autorizada)->where('ambiente', AmbienteFiscal::Homologacao->value)->exists();
        if (! $temTeste) {
            throw new EmissaoBloqueadaException('FISCAL_SEM_EMISSAO_DE_TESTE', 'Emita ao menos uma NF-e de teste em homologação antes de ir para produção.');
        }

        return DB::transaction(function () use ($emitente, $autor, $numerosIniciais): Emitente {
            // Reentrada: com nota numerada em produção o contador é a verdade e nunca se reinicia.
            $jaNumerouEmProducao = Nota::query()->where('ambiente', AmbienteFiscal::Producao->value)->whereNotNull('numero')->exists();
            if (! $jaNumerouEmProducao) {
                foreach (EmitenteSerie::query()->where('emitente_id', $emitente->id)->lockForUpdate()->get() as $serie) {
                    $serie->definirProximoNumero(AmbienteFiscal::Producao, $numerosIniciais[$serie->modelo->value] ?? 1);
                }
            }

            $emitente->update(['ambiente_fiscal' => AmbienteFiscal::Producao]);

            activity('fiscal')->performedOn($emitente)->causedBy($autor)->event('ambiente_producao_confirmado')
                ->log('Ambiente fiscal alterado para produção');

            return $emitente;
        });
    }
}

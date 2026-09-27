<?php

declare(strict_types=1);

namespace App\Modules\Platform\Application\Actions;

use App\Modules\Identity\Domain\Models\Usuario;
use App\Modules\Platform\Domain\Exceptions\TransicaoDeSituacaoInvalidaException;
use App\Modules\Tenancy\Domain\Enums\SituacaoAssinatura;
use App\Modules\Tenancy\Domain\Models\Tenant;
use Illuminate\Support\Facades\DB;

final class MudarSituacaoDaEmpresa
{
    /** $autor null = sistema (ex.: expiração automática do teste). */
    public function executar(Tenant $tenant, SituacaoAssinatura $destino, string $motivo, ?Usuario $autor): Tenant
    {
        return DB::transaction(function () use ($tenant, $destino, $motivo, $autor): Tenant {
            /** @var Tenant $atual */
            $atual = Tenant::query()->lockForUpdate()->findOrFail($tenant->id);
            $origem = $atual->situacao;

            if (! $origem->podeIrPara($destino)) {
                throw TransicaoDeSituacaoInvalidaException::de($origem, $destino);
            }

            $atual->forceFill(['situacao' => $destino, 'situacao_alterada_em' => now()])->save();

            $registro = activity('assinatura')
                ->performedOn($atual)
                ->event('situacao_alterada')
                ->withProperties(['de' => $origem->value, 'para' => $destino->value, 'motivo' => $motivo]);
            if ($autor !== null) {
                $registro->causedBy($autor);
            }
            $registro->log('Situação da assinatura alterada');

            $tenant->setRawAttributes($atual->getAttributes(), true);

            return $tenant;
        });
    }
}

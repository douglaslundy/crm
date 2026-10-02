<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Console;

use App\Modules\Fiscal\Application\Actions\ConciliarNota;
use App\Modules\Fiscal\Domain\Enums\StatusNota;
use App\Modules\Fiscal\Domain\Models\Nota;
use App\Modules\Tenancy\Domain\TenantContext;
use Illuminate\Console\Command;
use Throwable;

final class ReconciliarNotasCommand extends Command
{
    protected $signature = 'fiscal:reconciliar-processando';

    protected $description = 'Concilia com a SEFAZ as notas que ficaram em PROCESSANDO (inclusive as nunca enviadas).';

    public function handle(ConciliarNota $conciliar, TenantContext $contexto): int
    {
        $notas = Nota::withoutTenantScope()->where('status', StatusNota::Processando)->orderBy('iniciada_em')->get();

        foreach ($notas as $nota) {
            $contexto->set($nota->tenant_id);
            try {
                $conciliar->executar($nota);
            } catch (Throwable $e) {
                report($e);
            } finally {
                $contexto->clear();
            }
        }

        $this->info("{$notas->count()} notas verificadas.");

        return self::SUCCESS;
    }
}

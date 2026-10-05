<?php

declare(strict_types=1);

namespace App\Modules\Fiscal\Console;

use App\Modules\Fiscal\Application\Actions\RetransmitirNota;
use App\Modules\Fiscal\Domain\Enums\StatusNota;
use App\Modules\Fiscal\Domain\Models\Nota;
use App\Modules\Tenancy\Domain\TenantContext;
use Illuminate\Console\Command;
use Throwable;

final class RetransmitirContingenciaCommand extends Command
{
    protected $signature = 'fiscal:retransmitir-contingencia';

    protected $description = 'Retransmite à SEFAZ as NFC-e emitidas em contingência (consulta a chave antes e reenvia o mesmo XML).';

    public function handle(RetransmitirNota $retransmitir, TenantContext $contexto): int
    {
        $notas = Nota::withoutTenantScope()->where('status', StatusNota::Contingencia)->orderBy('contingencia_limite_em')->get();

        foreach ($notas as $nota) {
            $contexto->set($nota->tenant_id);
            try {
                $retransmitir->executar($nota->id);
            } catch (Throwable $e) {
                report($e);
            } finally {
                $contexto->clear();
            }
        }

        $this->info("{$notas->count()} notas em contingência verificadas.");

        return self::SUCCESS;
    }
}

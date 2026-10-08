<?php

declare(strict_types=1);

namespace App\Modules\Shared\Console;

use App\Modules\Shared\Domain\Models\Idempotencia;
use Illuminate\Console\Command;

final class LimparIdempotenciasCommand extends Command
{
    protected $signature = 'api:limpar-idempotencias';

    protected $description = 'Apaga os registros de Idempotency-Key expirados (todas as empresas).';

    public function handle(): int
    {
        $apagados = Idempotencia::withoutTenantScope()->where('expira_em', '<', now())->delete();
        $this->info("Registros de idempotência apagados: {$apagados}.");

        return self::SUCCESS;
    }
}

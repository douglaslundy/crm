<?php

declare(strict_types=1);

namespace App\Modules\Platform\Console;

use App\Modules\Platform\Application\Actions\MudarSituacaoDaEmpresa;
use App\Modules\Tenancy\Domain\Enums\SituacaoAssinatura;
use App\Modules\Tenancy\Domain\Models\Tenant;
use Illuminate\Console\Command;

final class ExpirarTestesCommand extends Command
{
    protected $signature = 'assinaturas:expirar-testes';

    protected $description = 'Suspende as contas cujo período de teste terminou.';

    public function handle(MudarSituacaoDaEmpresa $mudarSituacao): int
    {
        // O teste vale até o fim do dia teste_termina_em, no fuso do negócio.
        $hoje = today((string) config('app.fuso_negocio'))->toDateString();
        $total = 0;

        // lazyById: seguro para alterar a coluna filtrada durante a iteração.
        Tenant::query()
            ->where('situacao', SituacaoAssinatura::Teste->value)
            ->whereDate('teste_termina_em', '<', $hoje)
            ->lazyById(100)
            ->each(function (Tenant $tenant) use ($mudarSituacao, &$total): void {
                $mudarSituacao->executar($tenant, SituacaoAssinatura::Suspensa, 'Período de teste encerrado.', null);
                $total++;
            });

        $this->info("{$total} conta(s) suspensa(s).");

        return self::SUCCESS;
    }
}

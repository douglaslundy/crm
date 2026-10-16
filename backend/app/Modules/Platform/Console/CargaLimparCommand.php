<?php

declare(strict_types=1);

namespace App\Modules\Platform\Console;

use App\Modules\Shared\Domain\AmbienteDeCarga;
use App\Modules\Tenancy\Domain\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * Remove só as empresas `CARGA-%` e o que elas criaram. As FKs de `tenant_id` não têm cascade, e a ordem das
 * tabelas filhas não é conhecida: cada passada apaga o que a FK deixar e repete até não restar nada.
 */
final class CargaLimparCommand extends Command
{
    private const MAX_PASSADAS = 12;

    protected $signature = 'carga:limpar';

    protected $description = 'Remove as empresas fictícias do teste de carga (só com APP_AMBIENTE=staging).';

    public function handle(): int
    {
        try {
            AmbienteDeCarga::exigir('carga:limpar');
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $ids = Tenant::query()->where('razao_social', 'like', 'CARGA-%')->pluck('id')->all();
        $tabelas = array_values(array_filter(
            array_map(fn (array $t): string => (string) $t['name'], Schema::getTables()),
            fn (string $nome): bool => Schema::hasColumn($nome, 'tenant_id'),
        ));

        $usuarios = DB::table('usuarios')->whereIn('tenant_id', $ids)->pluck('id')->all();
        DB::table('personal_access_tokens')->whereIn('tokenable_id', $usuarios)->delete();

        $pendentes = $tabelas;
        for ($passada = 0; $passada < self::MAX_PASSADAS && $pendentes !== []; $passada++) {
            $pendentes = array_values(array_filter($pendentes, fn (string $tabela): bool => ! $this->esvaziar($tabela, $ids)));
        }
        if ($pendentes !== []) {
            $this->error('Não consegui esvaziar: '.implode(', ', $pendentes));

            return self::FAILURE;
        }

        DB::table('tenants')->whereIn('id', $ids)->delete();
        DB::table('planos')->where('nome', CargaSemearCommand::NOME_DO_PLANO)->get()->each(function (object $plano): void {
            DB::table('plano_limites')->where('plano_id', $plano->id)->delete();
            DB::table('plano_modulos')->where('plano_id', $plano->id)->delete();
            DB::table('planos')->where('id', $plano->id)->delete();
        });
        $this->info(count($ids).' empresas de carga removidas.');

        return self::SUCCESS;
    }

    /** @param  list<string>  $ids */
    private function esvaziar(string $tabela, array $ids): bool
    {
        try {
            DB::transaction(fn () => DB::table($tabela)->whereIn('tenant_id', $ids)->delete());
        } catch (QueryException) {
            return false; // FK de uma tabela filha ainda cheia: tenta de novo na próxima passada.
        }

        return true;
    }
}

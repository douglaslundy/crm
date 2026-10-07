<?php

declare(strict_types=1);

namespace App\Modules\Billing\Console;

use App\Modules\Billing\Application\Actions\FecharExcedenteDoMes;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/** Fecha o documento excedente do mês anterior (ou do mês de `--mes=AAAA-MM`). Idempotente. */
final class FecharExcedenteCommand extends Command
{
    protected $signature = 'billing:fechar-excedente {--mes= : Mês a fechar, no formato AAAA-MM (padrão: o mês anterior)}';

    protected $description = 'Cria a cobrança de documentos acima do limite do plano referente a um mês.';

    public function handle(FecharExcedenteDoMes $fechar): int
    {
        $fuso = (string) config('app.fuso_negocio');
        $informado = $this->option('mes');

        if ($informado === null || $informado === '') {
            $mes = CarbonImmutable::today($fuso)->startOfMonth()->subMonth();
        } elseif (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $informado) === 1) {
            $mes = CarbonImmutable::createFromFormat('!Y-m', $informado, $fuso);
        } else {
            $this->error('Informe o mês no formato AAAA-MM (por exemplo, 2026-10).');

            return self::FAILURE;
        }

        $criadas = $fechar->executar($mes);
        $this->info("{$criadas} cobrança(s) de excedente criada(s) para {$mes->format('Y-m')}.");

        return self::SUCCESS;
    }
}

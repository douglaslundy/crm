<?php

declare(strict_types=1);

namespace App\Modules\Crm\Application;

use App\Modules\Crm\Domain\Models\ConfiguracaoDoCrm;
use App\Modules\Crm\Domain\Models\Funil;
use App\Modules\Crm\Domain\Models\MotivoDePerda;
use App\Modules\Identity\Domain\Models\Usuario;
use App\Modules\Tenancy\Domain\Models\Tenant;
use Illuminate\Support\Facades\DB;

/** Semeia o CRM da empresa no primeiro uso. Idempotente. */
final class PreparadorDoCrm
{
    private const ETAPAS = [['Qualificação', 10], ['Proposta', 40], ['Negociação', 70]];

    private const MOTIVOS = ['Preço', 'Concorrente', 'Sem resposta', 'Outro'];

    public function garantir(Usuario $autor): void
    {
        DB::transaction(function () use ($autor): void {
            // Serializa a semeadura de uma mesma empresa.
            Tenant::query()->lockForUpdate()->findOrFail($autor->tenant_id);

            if (! Funil::query()->where('padrao', true)->exists()) {
                $funil = Funil::create(['nome' => 'Vendas', 'padrao' => true, 'ativo' => true, 'posicao' => 0]);
                foreach (self::ETAPAS as $posicao => [$nome, $probabilidade]) {
                    $funil->etapas()->create(['nome' => $nome, 'posicao' => $posicao, 'probabilidade' => $probabilidade, 'ativa' => true]);
                }
            }

            if (! MotivoDePerda::query()->exists()) {
                foreach (self::MOTIVOS as $nome) {
                    MotivoDePerda::create(['nome' => $nome, 'ativo' => true]);
                }
            }

            if (! ConfiguracaoDoCrm::query()->exists()) {
                ConfiguracaoDoCrm::create(['vendedor_ve_so_os_seus' => true]);
            }
        });
    }
}

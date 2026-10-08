<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Troca de plano: upgrade pago (cobrança com plano destino) e downgrade agendado para o fim do período. */
    public function up(): void
    {
        $this->semIndicesParciais(function (): void {
            Schema::table('assinaturas', function (Blueprint $table): void {
                $table->unsignedBigInteger('valor_ciclo_centavos')->nullable();
                $table->foreignUuid('plano_proximo_id')->nullable()->constrained('planos');
                $table->date('plano_proximo_em')->nullable();
            });
            Schema::table('cobrancas', function (Blueprint $table): void {
                $table->foreignUuid('plano_destino_id')->nullable()->constrained('planos');
                // Estado da assinatura quando o upgrade foi pedido: pagamento depois de renovação ou de outra troca é obsoleto.
                $table->foreignUuid('plano_origem_id')->nullable()->constrained('planos');
                $table->date('paga_ate_origem')->nullable();
            });
        });

        DB::statement("CREATE UNIQUE INDEX cobrancas_um_upgrade_pendente ON cobrancas (assinatura_id) WHERE tipo = 'UPGRADE' AND status = 'PENDENTE'");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX cobrancas_um_upgrade_pendente');

        $this->semIndicesParciais(function (): void {
            Schema::table('cobrancas', function (Blueprint $table): void {
                $table->dropConstrainedForeignId('plano_destino_id');
                $table->dropConstrainedForeignId('plano_origem_id');
                $table->dropColumn('paga_ate_origem');
            });
            Schema::table('assinaturas', function (Blueprint $table): void {
                $table->dropConstrainedForeignId('plano_proximo_id');
                $table->dropColumn(['valor_ciclo_centavos', 'plano_proximo_em']);
            });
        });
    }

    /**
     * No SQLite, alterar a tabela a recria e perde a condição (WHERE) dos índices únicos parciais, que viram únicos
     * simples. Por isso os índices parciais das migrations anteriores saem antes e voltam depois.
     */
    private function semIndicesParciais(callable $alterar): void
    {
        DB::statement('DROP INDEX IF EXISTS assinaturas_uma_nao_cancelada_por_tenant');
        DB::statement('DROP INDEX IF EXISTS cobrancas_um_excedente_por_mes');

        $alterar();

        DB::statement("CREATE UNIQUE INDEX assinaturas_uma_nao_cancelada_por_tenant ON assinaturas (tenant_id) WHERE situacao <> 'CANCELADA'");
        DB::statement("CREATE UNIQUE INDEX cobrancas_um_excedente_por_mes ON cobrancas (tenant_id, mes_referencia) WHERE tipo = 'EXCEDENTE' AND status <> 'CANCELADA'");
    }
};

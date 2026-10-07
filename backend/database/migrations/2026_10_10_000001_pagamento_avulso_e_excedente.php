<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Pagamento avulso (PIX e cartão) e cobrança de documento excedente: o método passa a ser escolhido ao pagar. */
    public function up(): void
    {
        Schema::table('cobrancas', function (Blueprint $table): void {
            $table->string('metodo', 10)->nullable()->change();
            $table->text('pix_qr_code')->nullable();
            $table->text('pix_qr_code_base64')->nullable();
            $table->timestamp('pix_expira_em')->nullable();
            $table->date('mes_referencia')->nullable();
            $table->unsignedInteger('documentos_excedentes')->nullable();
            $table->unsignedBigInteger('valor_unitario_centavos')->nullable();
        });

        // Torna o fechamento mensal idempotente: um excedente não cancelado por tenant e mês.
        DB::statement("CREATE UNIQUE INDEX cobrancas_um_excedente_por_mes ON cobrancas (tenant_id, mes_referencia) WHERE tipo = 'EXCEDENTE' AND status <> 'CANCELADA'");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX cobrancas_um_excedente_por_mes');

        Schema::table('cobrancas', function (Blueprint $table): void {
            $table->dropColumn(['pix_qr_code', 'pix_qr_code_base64', 'pix_expira_em', 'mes_referencia', 'documentos_excedentes', 'valor_unitario_centavos']);
        });
    }
};

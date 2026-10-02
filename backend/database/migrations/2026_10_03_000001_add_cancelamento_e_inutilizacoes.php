<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notas', function (Blueprint $table): void {
            $table->timestamp('cancelamento_solicitado_em')->nullable();
            $table->text('cancelamento_justificativa')->nullable();
            $table->string('cancelamento_protocolo', 20)->nullable();
            $table->timestamp('cancelado_em')->nullable();
            $table->string('cancelamento_cstat', 4)->nullable();
            $table->text('cancelamento_motivo')->nullable();
            $table->longText('xml_cancelamento')->nullable();
        });

        Schema::create('inutilizacoes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants');
            $table->foreignUuid('emitente_id')->constrained('emitentes');
            $table->string('serie', 3);
            $table->unsignedBigInteger('numero_inicial');
            $table->unsignedBigInteger('numero_final');
            $table->text('justificativa');
            $table->string('ambiente', 12);
            $table->string('status', 12);
            $table->string('cstat', 4)->nullable();
            $table->text('motivo')->nullable();
            $table->string('protocolo', 20)->nullable();
            $table->longText('xml_resposta')->nullable();
            $table->foreignUuid('usuario_id')->nullable()->constrained('usuarios');
            $table->timestamps();
            $table->index(['tenant_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inutilizacoes');
        Schema::table('notas', function (Blueprint $table): void {
            $table->dropColumn([
                'cancelamento_solicitado_em', 'cancelamento_justificativa', 'cancelamento_protocolo', 'cancelado_em',
                'cancelamento_cstat', 'cancelamento_motivo', 'xml_cancelamento',
            ]);
        });
    }
};

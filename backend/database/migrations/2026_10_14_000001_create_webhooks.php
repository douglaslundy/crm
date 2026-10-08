<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhook_endpoints', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants');
            $table->foreignUuid('usuario_id')->constrained('usuarios');
            $table->string('url', 500);
            $table->string('descricao', 80)->nullable();
            $table->json('eventos');
            $table->string('ambiente', 11);
            $table->text('segredo');
            $table->boolean('ativo')->default(true);
            $table->timestamp('desativado_em')->nullable();
            $table->string('motivo_desativacao', 255)->nullable();
            $table->unsignedInteger('falhas_seguidas')->default(0);
            $table->timestamps();
            $table->index('tenant_id');
        });

        Schema::create('webhook_entregas', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants');
            $table->foreignUuid('webhook_endpoint_id')->constrained('webhook_endpoints')->cascadeOnDelete();
            $table->unsignedBigInteger('nota_evento_id')->nullable();
            $table->foreignUuid('nota_id')->nullable()->constrained('notas')->nullOnDelete();
            $table->string('evento', 40);
            $table->uuid('evento_id');
            $table->json('payload');
            $table->string('estado', 10)->default('PENDENTE');
            $table->unsignedInteger('tentativas')->default(0);
            // Tentativas da rodada atual: o reenvio manual abre uma nova rodada de até 7, sem apagar o histórico.
            $table->unsignedInteger('tentativas_na_rodada')->default(0);
            $table->timestamp('proxima_tentativa_em')->nullable();
            $table->timestamp('entregue_em')->nullable();
            $table->timestamps();
            $table->unique(['webhook_endpoint_id', 'nota_evento_id']);
            $table->index(['estado', 'proxima_tentativa_em']);
            $table->index(['webhook_endpoint_id', 'created_at']);
        });

        Schema::create('webhook_tentativas', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants');
            $table->foreignUuid('webhook_entrega_id')->constrained('webhook_entregas')->cascadeOnDelete();
            $table->unsignedInteger('numero');
            $table->unsignedSmallInteger('status_http')->nullable();
            $table->unsignedInteger('duracao_ms')->default(0);
            $table->string('erro', 500)->nullable();
            $table->string('resposta', 1024)->nullable();
            $table->timestamp('created_at')->nullable();
            $table->index(['webhook_entrega_id', 'numero']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_tentativas');
        Schema::dropIfExists('webhook_entregas');
        Schema::dropIfExists('webhook_endpoints');
    }
};

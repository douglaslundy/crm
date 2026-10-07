<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assinaturas', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants');
            $table->foreignUuid('plano_id')->constrained('planos');
            $table->string('ciclo', 10);
            $table->string('metodo', 10);
            $table->string('situacao', 12)->default('PENDENTE');
            $table->string('gateway_assinatura_id', 100)->nullable()->index();
            $table->date('paga_ate')->nullable();
            $table->timestamp('cancelada_em')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'situacao']);
        });

        Schema::create('cobrancas', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants');
            $table->foreignUuid('assinatura_id')->constrained('assinaturas');
            $table->string('tipo', 12);
            $table->unsignedBigInteger('valor_centavos');
            $table->date('vence_em');
            $table->string('status', 10)->default('PENDENTE');
            $table->string('metodo', 10);
            $table->string('gateway_pagamento_id', 100)->nullable()->unique();
            $table->text('boleto_url')->nullable();
            $table->string('boleto_linha_digitavel', 100)->nullable();
            $table->timestamp('pago_em')->nullable();
            $table->date('periodo_inicio')->nullable();
            $table->date('periodo_fim')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'status']);
            $table->index(['status', 'vence_em']);
        });

        // Sem tenant_id: o evento chega antes de se saber de quem é o pagamento.
        Schema::create('webhooks_recebidos', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('gateway_evento_id', 100)->unique();
            $table->string('tipo', 60);
            $table->timestamp('recebido_em');
            $table->timestamp('processado_em')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhooks_recebidos');
        Schema::dropIfExists('cobrancas');
        Schema::dropIfExists('assinaturas');
    }
};

<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notas', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants');
            $table->foreignUuid('emitente_id')->constrained('emitentes');
            $table->foreignUuid('cliente_id')->constrained('clientes');
            $table->string('status', 12)->default('RASCUNHO');
            $table->string('ambiente', 12)->nullable();
            $table->string('natureza_operacao', 60)->default('Venda de mercadoria');
            $table->boolean('consumidor_final')->nullable();
            $table->bigInteger('subtotal_centavos')->default(0);
            $table->bigInteger('desconto_centavos')->default(0);
            $table->bigInteger('total_centavos')->default(0);
            $table->text('informacoes_complementares')->nullable();
            $table->json('destinatario')->nullable();
            $table->string('serie', 3)->nullable();
            $table->unsignedBigInteger('numero')->nullable();
            $table->unsignedSmallInteger('tentativas_numero')->default(0);
            $table->string('chave', 44)->nullable();
            $table->string('protocolo', 20)->nullable();
            $table->string('cstat', 4)->nullable();
            $table->text('motivo')->nullable();
            $table->text('mensagem_erro')->nullable();
            $table->longText('xml_enviado')->nullable();
            $table->longText('xml_autorizado')->nullable();
            $table->timestamp('iniciada_em')->nullable();
            $table->timestamp('emitido_em')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'created_at']);
            $table->unique(['tenant_id', 'ambiente', 'serie', 'numero']);
            $table->index('chave');
        });

        Schema::create('nota_itens', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('tenant_id')->constrained('tenants');
            $table->foreignUuid('nota_id')->constrained('notas')->cascadeOnDelete();
            $table->foreignUuid('produto_id')->constrained('produtos');
            $table->unsignedSmallInteger('ordem');
            $table->unsignedBigInteger('quantidade_milesimos');
            $table->bigInteger('valor_unitario_centavos');
            $table->bigInteger('desconto_centavos')->default(0);
            $table->bigInteger('total_centavos')->default(0);
            $table->string('sku', 60)->nullable();
            $table->string('descricao', 120)->nullable();
            $table->string('unidade', 6)->nullable();
            $table->string('gtin', 14)->nullable();
            $table->string('ncm', 8)->nullable();
            $table->string('cest', 7)->nullable();
            $table->string('cfop', 4)->nullable();
            $table->smallInteger('origem')->nullable();
            $table->string('tributacao_icms', 6)->nullable();
            $table->string('csosn', 3)->nullable();
            $table->timestamps();
        });

        Schema::create('nota_pagamentos', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('tenant_id')->constrained('tenants');
            $table->foreignUuid('nota_id')->constrained('notas')->cascadeOnDelete();
            $table->string('tpag', 2);
            $table->string('xpag', 60)->nullable();
            $table->bigInteger('valor_centavos');
            $table->timestamps();
        });

        Schema::create('nota_eventos', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('tenant_id')->constrained('tenants');
            $table->foreignUuid('nota_id')->constrained('notas')->cascadeOnDelete();
            $table->string('status_de', 12)->nullable();
            $table->string('status_para', 12);
            $table->text('detalhe')->nullable();
            $table->foreignUuid('usuario_id')->nullable()->constrained('usuarios');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nota_eventos');
        Schema::dropIfExists('nota_pagamentos');
        Schema::dropIfExists('nota_itens');
        Schema::dropIfExists('notas');
    }
};

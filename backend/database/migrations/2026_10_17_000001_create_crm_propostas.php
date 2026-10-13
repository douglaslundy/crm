<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_propostas', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants');
            $table->foreignUuid('negocio_id')->constrained('crm_negocios');
            $table->uuid('cliente_id');   // sem FK: pertence a outro módulo
            $table->unsignedInteger('numero');
            $table->string('status', 12);
            $table->unsignedSmallInteger('validade_dias');
            $table->timestamp('validade_em')->nullable();
            $table->unsignedBigInteger('desconto_centavos')->default(0);
            $table->text('observacoes')->nullable();
            $table->string('token_hash', 64)->nullable()->unique();
            $table->text('token')->nullable();   // criptografado (cast): reexibe o link ao vendedor
            $table->timestamp('enviada_em')->nullable();
            $table->timestamp('respondida_em')->nullable();
            $table->string('aceite_nome', 120)->nullable();
            $table->string('aceite_ip', 45)->nullable();
            $table->text('motivo_recusa')->nullable();
            $table->json('notas_ids')->nullable();
            $table->uuid('criado_por');
            $table->timestamps();
            $table->unique(['tenant_id', 'numero']);
            $table->index(['tenant_id', 'negocio_id', 'status']);
        });

        Schema::create('crm_itens_da_proposta', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants');
            $table->foreignUuid('proposta_id')->constrained('crm_propostas')->cascadeOnDelete();
            $table->string('tipo', 8);
            $table->uuid('item_id');
            $table->string('descricao', 255);
            $table->unsignedInteger('quantidade');
            $table->unsignedBigInteger('valor_unitario_centavos');
            $table->unsignedSmallInteger('ordem');
            $table->timestamps();
            $table->index(['tenant_id', 'proposta_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_itens_da_proposta');
        Schema::dropIfExists('crm_propostas');
    }
};

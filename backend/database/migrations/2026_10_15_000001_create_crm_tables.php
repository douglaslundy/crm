<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_configuracoes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->unique()->constrained('tenants');
            $table->boolean('vendedor_ve_so_os_seus')->default(true);
            $table->timestamps();
        });

        Schema::create('crm_funis', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants');
            $table->string('nome', 100);
            $table->boolean('padrao')->default(false);
            $table->boolean('ativo')->default(true);
            $table->unsignedInteger('posicao')->default(0);
            $table->timestamps();
            $table->index(['tenant_id', 'posicao']);
        });

        Schema::create('crm_etapas', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants');
            $table->foreignUuid('funil_id')->constrained('crm_funis')->cascadeOnDelete();
            $table->string('nome', 100);
            $table->unsignedInteger('posicao')->default(0);
            $table->unsignedTinyInteger('probabilidade')->default(0);
            $table->boolean('ativa')->default(true);
            $table->timestamps();
            $table->index(['tenant_id', 'funil_id', 'posicao']);
        });

        Schema::create('crm_motivos_de_perda', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants');
            $table->string('nome', 100);
            $table->boolean('ativo')->default(true);
            $table->timestamps();
            $table->index(['tenant_id', 'ativo']);
        });

        Schema::create('crm_negocios', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants');
            $table->string('titulo', 150);
            // Sem chave estrangeira: cliente e responsável pertencem a outros módulos (spec §3).
            $table->uuid('cliente_id');
            $table->foreignUuid('funil_id')->constrained('crm_funis');
            $table->foreignUuid('etapa_id')->constrained('crm_etapas');
            $table->uuid('responsavel_id');
            $table->string('status', 10)->default('ABERTO');
            $table->unsignedBigInteger('valor_centavos')->default(0);
            $table->date('previsao_fechamento')->nullable();
            $table->unsignedInteger('posicao_na_etapa')->default(0);
            $table->foreignUuid('motivo_de_perda_id')->nullable()->constrained('crm_motivos_de_perda');
            $table->text('observacao_da_perda')->nullable();
            $table->timestamp('fechado_em')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'funil_id', 'status']);
            $table->index(['tenant_id', 'etapa_id', 'posicao_na_etapa']);
            $table->index(['tenant_id', 'responsavel_id']);
            $table->index(['tenant_id', 'cliente_id']);
        });

        Schema::create('crm_itens_do_negocio', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants');
            $table->foreignUuid('negocio_id')->constrained('crm_negocios')->cascadeOnDelete();
            $table->string('tipo', 10);
            $table->uuid('item_id');
            $table->string('descricao', 150);
            $table->unsignedInteger('quantidade');
            $table->unsignedBigInteger('valor_unitario_centavos');
            $table->timestamps();
            $table->index(['tenant_id', 'negocio_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_itens_do_negocio');
        Schema::dropIfExists('crm_negocios');
        Schema::dropIfExists('crm_motivos_de_perda');
        Schema::dropIfExists('crm_etapas');
        Schema::dropIfExists('crm_funis');
        Schema::dropIfExists('crm_configuracoes');
    }
};

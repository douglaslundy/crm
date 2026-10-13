<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_atividades', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants');
            // Sem chave estrangeira para cliente e usuário: pertencem a outros módulos.
            $table->uuid('cliente_id');
            $table->foreignUuid('negocio_id')->nullable()->constrained('crm_negocios');
            $table->string('tipo', 12);
            $table->string('titulo', 150);
            $table->text('descricao')->nullable();
            $table->timestamp('agendada_para');
            $table->timestamp('concluida_em')->nullable();
            $table->uuid('responsavel_id');
            $table->uuid('criado_por');
            $table->timestamps();
            $table->index(['tenant_id', 'responsavel_id', 'concluida_em', 'agendada_para'], 'crm_atividades_agenda');
            $table->index(['tenant_id', 'cliente_id']);
            $table->index(['tenant_id', 'negocio_id']);
        });

        Schema::create('crm_timeline', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants');
            $table->uuid('cliente_id');
            $table->foreignUuid('negocio_id')->nullable()->constrained('crm_negocios');
            $table->uuid('atividade_id')->nullable();
            $table->string('tipo', 24);
            $table->text('texto');
            $table->json('dados')->nullable();
            $table->uuid('autor_id')->nullable();
            $table->timestamp('ocorreu_em');
            $table->index(['tenant_id', 'cliente_id', 'ocorreu_em', 'id'], 'crm_timeline_cliente');
            $table->index(['tenant_id', 'negocio_id', 'tipo']);
            $table->index(['tenant_id', 'atividade_id']);
        });

        Schema::table('crm_etapas', function (Blueprint $table): void {
            $table->unsignedSmallInteger('dias_para_parado')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('crm_etapas', function (Blueprint $table): void {
            $table->dropColumn('dias_para_parado');
        });
        Schema::dropIfExists('crm_timeline');
        Schema::dropIfExists('crm_atividades');
    }
};

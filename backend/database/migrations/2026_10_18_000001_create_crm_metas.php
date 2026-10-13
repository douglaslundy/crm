<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_metas', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants');
            $table->uuid('usuario_id');
            $table->date('mes');
            $table->string('unidade', 10);
            $table->unsignedBigInteger('alvo');
            $table->timestamps();
            $table->unique(['tenant_id', 'usuario_id', 'mes']);
        });

        Schema::table('crm_negocios', function (Blueprint $table): void {
            $table->index(['tenant_id', 'status', 'fechado_em'], 'crm_negocios_fechamento');
        });
    }

    public function down(): void
    {
        Schema::table('crm_negocios', function (Blueprint $table): void {
            $table->dropIndex('crm_negocios_fechamento');
        });
        Schema::dropIfExists('crm_metas');
    }
};

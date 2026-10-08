<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('idempotencias', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants');
            $table->string('chave', 255);
            $table->string('rota', 160);
            $table->string('hash_do_corpo', 64);
            // Nulos enquanto a requisição original ainda está em andamento.
            $table->unsignedSmallInteger('status_http')->nullable();
            $table->longText('corpo')->nullable();
            $table->string('tipo_do_conteudo', 100)->nullable();
            $table->uuid('nota_id')->nullable();
            $table->timestamp('expira_em');
            $table->timestamps();
            $table->unique(['tenant_id', 'rota', 'chave']);
            $table->index('expira_em');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('idempotencias');
    }
};

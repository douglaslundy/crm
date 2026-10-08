<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chaves_api', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants');
            $table->foreignUuid('usuario_id')->constrained('usuarios');
            $table->string('nome', 80);
            $table->string('ambiente', 4);
            $table->string('prefixo', 12);
            $table->string('ultimos4', 4);
            $table->string('hash', 64)->unique();
            $table->timestamp('ultimo_uso_em')->nullable();
            $table->timestamp('revogada_em')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'revogada_em']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chaves_api');
    }
};

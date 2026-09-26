<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('planos', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('nome', 60)->unique();
            $table->text('descricao')->nullable();
            $table->integer('preco_mensal_centavos');
            $table->integer('preco_anual_centavos')->nullable();
            $table->unsignedSmallInteger('dias_teste')->default(0);
            $table->string('politica_excedente', 10)->default('BLOQUEAR');
            $table->integer('preco_documento_excedente_centavos')->nullable();
            $table->boolean('ativo')->default(true);
            $table->boolean('visivel')->default(true);
            $table->unsignedSmallInteger('ordem')->default(0);
            $table->timestamps();
        });

        Schema::create('plano_modulos', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('plano_id')->constrained('planos')->cascadeOnDelete();
            $table->string('modulo', 20);
            $table->unique(['plano_id', 'modulo']);
        });

        Schema::create('plano_limites', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('plano_id')->constrained('planos')->cascadeOnDelete();
            $table->string('recurso', 30);
            $table->integer('limite'); // -1 = ilimitado; recurso sem linha = 0
            $table->unique(['plano_id', 'recurso']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plano_limites');
        Schema::dropIfExists('plano_modulos');
        Schema::dropIfExists('planos');
    }
};

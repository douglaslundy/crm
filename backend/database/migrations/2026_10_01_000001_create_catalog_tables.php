<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('produtos', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants');
            $table->string('sku', 60);
            $table->string('nome', 150);
            $table->string('unidade', 10);
            $table->integer('preco_centavos');
            $table->string('gtin', 14)->nullable();
            $table->string('ncm', 8)->nullable();
            $table->string('cest', 7)->nullable();
            $table->smallInteger('origem')->nullable();
            $table->string('tributacao_icms', 10)->nullable();
            $table->string('fiscal_fonte', 10)->default('MANUAL');
            $table->timestamp('fiscal_revisado_em')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'sku']);
        });

        Schema::create('servicos', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants');
            $table->string('nome', 150);
            $table->integer('preco_centavos');
            $table->string('codigo_lc116', 5)->nullable();
            $table->string('c_trib_nac', 6)->nullable();
            $table->string('codigo_municipal', 3)->nullable();
            $table->decimal('aliquota_iss', 5, 2)->nullable();
            $table->string('nbs', 9)->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'nome']);
        });

        Schema::create('categorias_fiscais_padrao', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants');
            $table->string('categoria', 60);
            $table->string('ncm', 8)->nullable();
            $table->smallInteger('origem')->nullable();
            $table->string('tributacao_icms', 10)->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'categoria']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('categorias_fiscais_padrao');
        Schema::dropIfExists('servicos');
        Schema::dropIfExists('produtos');
    }
};

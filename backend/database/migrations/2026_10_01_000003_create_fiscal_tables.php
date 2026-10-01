<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('emitentes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants');
            $table->string('logradouro', 150)->nullable();
            $table->string('numero', 20)->nullable();
            $table->string('bairro', 100)->nullable();
            $table->string('cidade', 100)->nullable();
            $table->string('uf', 2)->nullable();
            $table->string('cep', 8)->nullable();
            $table->string('codigo_ibge', 7)->nullable();
            $table->string('regime_tributario', 30)->nullable();
            $table->string('inscricao_estadual', 20)->nullable();
            $table->string('inscricao_municipal', 20)->nullable();
            $table->string('cnae', 10)->nullable();
            $table->string('ambiente_fiscal', 12)->default('HOMOLOGACAO');
            $table->text('certificado_pfx_encrypted')->nullable();
            $table->text('certificado_senha_encrypted')->nullable();
            $table->date('certificado_validade')->nullable();
            $table->string('certificado_titular')->nullable();
            $table->string('certificado_status', 10)->default('PENDENTE');
            $table->string('csc_id_homologacao', 10)->nullable();
            $table->text('csc_token_homologacao_encrypted')->nullable();
            $table->string('csc_id_producao', 10)->nullable();
            $table->text('csc_token_producao_encrypted')->nullable();
            $table->timestamps();
            $table->unique('tenant_id');
        });

        Schema::create('emitente_series', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('tenant_id')->constrained('tenants');
            $table->foreignUuid('emitente_id')->constrained('emitentes')->cascadeOnDelete();
            $table->string('modelo', 5);
            $table->string('serie', 3);
            $table->unsignedBigInteger('proximo_numero')->default(1);
            $table->timestamps();
            $table->unique(['tenant_id', 'modelo']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('emitente_series');
        Schema::dropIfExists('emitentes');
    }
};

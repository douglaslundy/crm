<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clientes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants');
            $table->string('tipo', 2);
            $table->string('nome', 150);
            $table->string('cpf_cnpj', 14)->nullable();
            $table->string('inscricao_estadual', 20)->nullable();
            $table->boolean('ie_isento')->default(false);
            $table->string('email', 150)->nullable();
            $table->string('telefone', 20)->nullable();
            $table->string('logradouro', 150)->nullable();
            $table->string('numero', 20)->nullable();
            $table->string('bairro', 100)->nullable();
            $table->string('cidade', 100)->nullable();
            $table->string('uf', 2)->nullable();
            $table->string('cep', 8)->nullable();
            $table->string('codigo_ibge', 7)->nullable();
            $table->json('tags')->nullable();
            $table->string('origem', 60)->nullable();
            $table->string('estagio', 10)->default('LEAD');
            $table->timestamps();
            // Postgres trata NULL como distinto em índice único: vários leads sem
            // CPF/CNPJ convivem, mas o mesmo CPF/CNPJ não duplica no mesmo tenant.
            $table->unique(['tenant_id', 'cpf_cnpj']);
            $table->index(['tenant_id', 'nome']);
        });

        Schema::create('contatos', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants');
            $table->foreignUuid('cliente_id')->constrained('clientes')->cascadeOnDelete();
            $table->string('nome', 150);
            $table->string('cargo', 100)->nullable();
            $table->string('email', 150)->nullable();
            $table->string('telefone', 20)->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'cliente_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contatos');
        Schema::dropIfExists('clientes');
    }
};

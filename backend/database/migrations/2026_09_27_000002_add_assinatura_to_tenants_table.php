<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            $table->renameColumn('nome', 'razao_social');
        });

        Schema::table('tenants', function (Blueprint $table): void {
            $table->string('nome_fantasia', 150)->nullable();
            $table->foreignUuid('plano_id')->nullable()->constrained('planos');
            $table->string('situacao', 20)->default('PENDENTE')->index();
            $table->date('teste_termina_em')->nullable();
            $table->timestamp('situacao_alterada_em')->nullable();
        });

        // O único status da F0 era ATIVO.
        DB::table('tenants')->where('status', 'ATIVO')->update(['situacao' => 'ATIVA']);

        Schema::table('tenants', function (Blueprint $table): void {
            $table->dropColumn('status');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            $table->string('status', 20)->default('ATIVO');
        });
        DB::table('tenants')->where('situacao', '!=', 'ATIVA')->update(['status' => 'INATIVO']);

        Schema::table('tenants', function (Blueprint $table): void {
            $table->dropForeign(['plano_id']);
            $table->dropIndex(['situacao']);
            $table->dropColumn(['nome_fantasia', 'plano_id', 'situacao', 'teste_termina_em', 'situacao_alterada_em']);
        });

        Schema::table('tenants', function (Blueprint $table): void {
            $table->renameColumn('razao_social', 'nome');
        });
    }
};

<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notas', function (Blueprint $table): void {
            // A chave da NFS-e Nacional tem 50 dígitos (a da NF-e, 44). `numero` continua sendo o nDPS.
            $table->string('chave', 50)->nullable()->change();
            $table->string('numero_nfse', 20)->nullable();
        });
        Schema::table('nota_itens', function (Blueprint $table): void {
            $table->uuid('produto_id')->nullable()->change();
            $table->uuid('servico_id')->nullable();
            $table->string('c_trib_nac', 6)->nullable();
            $table->string('codigo_municipal', 3)->nullable();
            $table->decimal('aliquota_iss', 5, 2)->nullable();
            $table->string('nbs', 9)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('nota_itens', function (Blueprint $table): void {
            $table->dropColumn(['servico_id', 'c_trib_nac', 'codigo_municipal', 'aliquota_iss', 'nbs']);
        });
        Schema::table('notas', function (Blueprint $table): void {
            $table->dropColumn('numero_nfse');
        });
    }
};

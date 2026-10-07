<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vendas', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants');
            $table->foreignUuid('cliente_id')->constrained('clientes');
            $table->unsignedBigInteger('desconto_centavos')->default(0);
            $table->unsignedBigInteger('subtotal_centavos')->default(0);
            $table->unsignedBigInteger('total_centavos')->default(0);
            $table->uuid('criada_por')->nullable();
            $table->timestamps();
        });
        Schema::table('notas', function (Blueprint $table): void {
            $table->foreignUuid('venda_id')->nullable()->constrained('vendas');
        });
    }

    public function down(): void
    {
        Schema::table('notas', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('venda_id');
        });
        Schema::dropIfExists('vendas');
    }
};

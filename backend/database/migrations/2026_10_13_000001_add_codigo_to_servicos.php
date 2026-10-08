<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('servicos', function (Blueprint $table): void {
            $table->string('codigo', 60)->nullable();
            // NULLs não colidem em SQLite nem em PostgreSQL: o índice composto basta, sem WHERE.
            $table->unique(['tenant_id', 'codigo']);
        });
    }

    public function down(): void
    {
        Schema::table('servicos', function (Blueprint $table): void {
            $table->dropUnique(['tenant_id', 'codigo']);
            $table->dropColumn('codigo');
        });
    }
};

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
            $table->dropUnique(['tenant_id', 'ambiente', 'serie', 'numero']);
        });
        Schema::table('notas', function (Blueprint $table): void {
            $table->string('modelo', 4)->default('NFE')->after('emitente_id');
            $table->uuid('cliente_id')->nullable()->change();
            $table->unique(['tenant_id', 'modelo', 'ambiente', 'serie', 'numero']);
        });
        Schema::table('inutilizacoes', function (Blueprint $table): void {
            $table->string('modelo', 4)->default('NFE')->after('emitente_id');
        });
    }

    public function down(): void
    {
        Schema::table('inutilizacoes', function (Blueprint $table): void {
            $table->dropColumn('modelo');
        });
        Schema::table('notas', function (Blueprint $table): void {
            $table->dropUnique(['tenant_id', 'modelo', 'ambiente', 'serie', 'numero']);
        });
        Schema::table('notas', function (Blueprint $table): void {
            $table->dropColumn('modelo');
            $table->unique(['tenant_id', 'ambiente', 'serie', 'numero']);
        });
    }
};

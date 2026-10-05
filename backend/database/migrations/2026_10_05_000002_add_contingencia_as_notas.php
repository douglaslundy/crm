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
            $table->timestamp('contingencia_desde')->nullable();
            $table->timestamp('contingencia_limite_em')->nullable();
            $table->timestamp('ultima_retransmissao_em')->nullable();
            $table->text('contingencia_motivo')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('notas', function (Blueprint $table): void {
            $table->dropColumn(['contingencia_desde', 'contingencia_limite_em', 'ultima_retransmissao_em', 'contingencia_motivo']);
        });
    }
};

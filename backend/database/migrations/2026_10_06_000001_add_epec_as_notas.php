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
        Schema::table('notas', function (Blueprint $table): void {
            $table->string('contingencia_tipo', 12)->nullable();
            $table->string('epec_protocolo')->nullable();
            $table->timestamp('epec_registrado_em')->nullable();
            $table->text('xml_epec')->nullable();
        });

        // Até a F3c2 só a NFC-e entrava em contingência, e sempre offline.
        DB::table('notas')->where('status', 'CONTINGENCIA')->update(['contingencia_tipo' => 'OFFLINE_NFCE']);
    }

    public function down(): void
    {
        Schema::table('notas', function (Blueprint $table): void {
            $table->dropColumn(['contingencia_tipo', 'epec_protocolo', 'epec_registrado_em', 'xml_epec']);
        });
    }
};

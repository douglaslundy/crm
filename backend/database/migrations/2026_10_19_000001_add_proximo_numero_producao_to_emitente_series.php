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
        Schema::table('emitente_series', function (Blueprint $table): void {
            // `proximo_numero` passa a valer só para homologação; produção tem contador próprio.
            $table->unsignedBigInteger('proximo_numero_producao')->default(1)->after('proximo_numero');
        });

        $this->herdarContadorDeQuemJaEstaEmProducao();
    }

    /**
     * Quem já emitia em produção numerou com o contador único: sem herdar, a produção recomeçaria do 1 e repetiria
     * número na SEFAZ (539).
     */
    public function herdarContadorDeQuemJaEstaEmProducao(): void
    {
        DB::table('emitente_series')
            ->whereIn('emitente_id', DB::table('emitentes')->where('ambiente_fiscal', 'PRODUCAO')->select('id'))
            ->update(['proximo_numero_producao' => DB::raw('proximo_numero')]);
    }

    public function down(): void
    {
        Schema::table('emitente_series', function (Blueprint $table): void {
            $table->dropColumn('proximo_numero_producao');
        });
    }
};

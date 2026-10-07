<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /** Uma assinatura não cancelada por tenant: rede de segurança do banco contra dois checkouts simultâneos. */
    public function up(): void
    {
        DB::statement("CREATE UNIQUE INDEX assinaturas_uma_nao_cancelada_por_tenant ON assinaturas (tenant_id) WHERE situacao <> 'CANCELADA'");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX assinaturas_uma_nao_cancelada_por_tenant');
    }
};

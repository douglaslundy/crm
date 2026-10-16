<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contatos_site', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('nome', 120);
            $table->string('email', 150);
            $table->text('mensagem');
            $table->string('ip', 45)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contatos_site');
    }
};

<?php

declare(strict_types=1);

use App\Modules\Crm\Http\Controllers\ConfiguracaoController;
use App\Modules\Crm\Http\Controllers\EtapasController;
use App\Modules\Crm\Http\Controllers\FunisController;
use App\Modules\Crm\Http\Controllers\MotivosDePerdaController;
use App\Modules\Crm\Http\Controllers\NegociosController;
use Illuminate\Support\Facades\Route;

Route::middleware('empresa')->group(function (): void {
    Route::get('funis', [FunisController::class, 'index'])->name('app.crm.funis.index');
    Route::post('funis', [FunisController::class, 'store'])->name('app.crm.funis.store');
    Route::put('funis/{id}', [FunisController::class, 'update'])->name('app.crm.funis.update');
    Route::post('funis/{funilId}/etapas', [EtapasController::class, 'store'])->name('app.crm.etapas.store');
    Route::put('funis/{funilId}/etapas/ordem', [EtapasController::class, 'ordenar'])->name('app.crm.etapas.ordenar');
    Route::put('etapas/{id}', [EtapasController::class, 'update'])->name('app.crm.etapas.update');

    Route::get('motivos-de-perda', [MotivosDePerdaController::class, 'index'])->name('app.crm.motivos.index');
    Route::post('motivos-de-perda', [MotivosDePerdaController::class, 'store'])->name('app.crm.motivos.store');
    Route::put('motivos-de-perda/{id}', [MotivosDePerdaController::class, 'update'])->name('app.crm.motivos.update');

    Route::get('negocios', [NegociosController::class, 'index'])->name('app.crm.negocios.index');
    Route::post('negocios', [NegociosController::class, 'store'])->name('app.crm.negocios.store');
    Route::get('negocios/{id}', [NegociosController::class, 'show'])->name('app.crm.negocios.show');
    Route::put('negocios/{id}', [NegociosController::class, 'update'])->name('app.crm.negocios.update');
    Route::put('negocios/{id}/itens', [NegociosController::class, 'itens'])->name('app.crm.negocios.itens');
    Route::post('negocios/{id}/mover', [NegociosController::class, 'mover'])->name('app.crm.negocios.mover');
    Route::post('negocios/{id}/ganhar', [NegociosController::class, 'ganhar'])->name('app.crm.negocios.ganhar');
    Route::post('negocios/{id}/perder', [NegociosController::class, 'perder'])->name('app.crm.negocios.perder');

    Route::get('configuracao', [ConfiguracaoController::class, 'show'])->name('app.crm.configuracao.show');
    Route::put('configuracao', [ConfiguracaoController::class, 'update'])->name('app.crm.configuracao.update');
});

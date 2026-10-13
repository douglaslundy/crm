<?php

declare(strict_types=1);

use App\Modules\Crm\Http\Controllers\AtividadesController;
use App\Modules\Crm\Http\Controllers\ConfiguracaoController;
use App\Modules\Crm\Http\Controllers\ContatosController;
use App\Modules\Crm\Http\Controllers\EtapasController;
use App\Modules\Crm\Http\Controllers\FunisController;
use App\Modules\Crm\Http\Controllers\MotivosDePerdaController;
use App\Modules\Crm\Http\Controllers\NegociosController;
use App\Modules\Crm\Http\Controllers\PropostasController;
use App\Modules\Crm\Http\Controllers\TimelineController;
use Illuminate\Support\Facades\Route;

Route::middleware('empresa')->whereUuid(['id', 'funilId'])->group(function (): void {
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

    Route::get('atividades', [AtividadesController::class, 'index'])->name('app.crm.atividades.index');
    Route::post('atividades', [AtividadesController::class, 'store'])->name('app.crm.atividades.store');
    Route::put('atividades/{id}', [AtividadesController::class, 'update'])->name('app.crm.atividades.update');
    Route::delete('atividades/{id}', [AtividadesController::class, 'destroy'])->name('app.crm.atividades.destroy');
    Route::post('atividades/{id}/concluir', [AtividadesController::class, 'concluir'])->name('app.crm.atividades.concluir');
    Route::post('atividades/{id}/reabrir', [AtividadesController::class, 'reabrir'])->name('app.crm.atividades.reabrir');
    Route::get('agenda', [AtividadesController::class, 'agenda'])->name('app.crm.agenda');
    Route::get('resumo', [AtividadesController::class, 'resumo'])->name('app.crm.resumo');
    Route::post('contatos', [ContatosController::class, 'store'])->name('app.crm.contatos.store');
    Route::get('clientes/{id}/timeline', [TimelineController::class, 'index'])->name('app.crm.timeline.index');
    Route::post('clientes/{id}/notas', [TimelineController::class, 'nota'])->name('app.crm.timeline.nota');

    Route::get('negocios/{id}/propostas', [PropostasController::class, 'index'])->name('app.crm.propostas.index');
    Route::post('negocios/{id}/propostas', [PropostasController::class, 'store'])->name('app.crm.propostas.store');
    Route::get('propostas/{id}', [PropostasController::class, 'show'])->name('app.crm.propostas.show');
    Route::put('propostas/{id}', [PropostasController::class, 'update'])->name('app.crm.propostas.update');
    Route::post('propostas/{id}/enviar', [PropostasController::class, 'enviar'])->name('app.crm.propostas.enviar');
    Route::post('propostas/{id}/cancelar', [PropostasController::class, 'cancelar'])->name('app.crm.propostas.cancelar');
    Route::post('propostas/{id}/nota', [PropostasController::class, 'nota'])->name('app.crm.propostas.nota');
    Route::get('propostas/{id}/pdf', [PropostasController::class, 'pdf'])->name('app.crm.propostas.pdf');

    Route::get('configuracao', [ConfiguracaoController::class, 'show'])->name('app.crm.configuracao.show');
    Route::put('configuracao', [ConfiguracaoController::class, 'update'])->name('app.crm.configuracao.update');
});

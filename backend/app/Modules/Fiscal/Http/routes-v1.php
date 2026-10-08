<?php

declare(strict_types=1);

use App\Modules\Fiscal\Http\Controllers\V1\EmpresaController;
use App\Modules\Fiscal\Http\Controllers\V1\InutilizacoesController;
use App\Modules\Fiscal\Http\Controllers\V1\NotasController;
use App\Modules\Fiscal\Http\Controllers\WebhooksController;
use Illuminate\Support\Facades\Route;

Route::middleware('api-v1')->group(function (): void {
    Route::get('empresa', EmpresaController::class);

    // Inutilizações antes de notas/{id}: o caminho literal tem que ser casado primeiro.
    Route::get('notas/inutilizacoes', [InutilizacoesController::class, 'index']);
    Route::post('notas/inutilizacoes', [InutilizacoesController::class, 'store'])->middleware('idempotencia');

    Route::post('notas', [NotasController::class, 'store'])->middleware('idempotencia:obrigatoria');
    Route::get('notas', [NotasController::class, 'index']);
    Route::get('notas/{id}', [NotasController::class, 'show'])->whereUuid('id');
    Route::get('notas/{id}/status', [NotasController::class, 'status'])->whereUuid('id');
    Route::get('notas/{id}/pdf', [NotasController::class, 'pdf'])->whereUuid('id');
    Route::get('notas/{id}/xml', [NotasController::class, 'xml'])->whereUuid('id');
    Route::get('notas/{id}/xml-cancelamento', [NotasController::class, 'xmlCancelamento'])->whereUuid('id');
    Route::post('notas/{id}/cancelar', [NotasController::class, 'cancelar'])->whereUuid('id')->middleware('idempotencia');

    // Entregas antes de webhooks/{id}; {id} é sempre uuid.
    Route::get('webhooks/entregas/{id}', [WebhooksController::class, 'entrega'])->whereUuid('id');
    Route::post('webhooks/entregas/{id}/reenviar', [WebhooksController::class, 'reenviar'])->whereUuid('id')->middleware('idempotencia');
    Route::get('webhooks', [WebhooksController::class, 'index']);
    // Sem idempotência: a resposta traz o segredo, que não pode ficar guardado em texto puro por 24 h.
    Route::post('webhooks', [WebhooksController::class, 'store']);
    Route::get('webhooks/{id}', [WebhooksController::class, 'show'])->whereUuid('id');
    Route::put('webhooks/{id}', [WebhooksController::class, 'update'])->whereUuid('id');
    Route::delete('webhooks/{id}', [WebhooksController::class, 'destroy'])->whereUuid('id');
    Route::get('webhooks/{id}/entregas', [WebhooksController::class, 'entregas'])->whereUuid('id');
    Route::post('webhooks/{id}/testar', [WebhooksController::class, 'testar'])->whereUuid('id')->middleware('idempotencia');
});

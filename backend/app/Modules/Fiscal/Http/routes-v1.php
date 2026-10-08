<?php

declare(strict_types=1);

use App\Modules\Fiscal\Http\Controllers\V1\EmpresaController;
use App\Modules\Fiscal\Http\Controllers\V1\NotasController;
use Illuminate\Support\Facades\Route;

Route::middleware('api-v1')->group(function (): void {
    Route::get('empresa', EmpresaController::class);

    Route::post('notas', [NotasController::class, 'store'])->middleware('idempotencia:obrigatoria');
    Route::get('notas', [NotasController::class, 'index']);
    Route::get('notas/{id}', [NotasController::class, 'show'])->whereUuid('id');
    Route::get('notas/{id}/status', [NotasController::class, 'status'])->whereUuid('id');
    Route::get('notas/{id}/pdf', [NotasController::class, 'pdf'])->whereUuid('id');
    Route::get('notas/{id}/xml', [NotasController::class, 'xml'])->whereUuid('id');
    Route::get('notas/{id}/xml-cancelamento', [NotasController::class, 'xmlCancelamento'])->whereUuid('id');
});

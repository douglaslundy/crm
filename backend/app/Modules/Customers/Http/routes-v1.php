<?php

declare(strict_types=1);

use App\Modules\Customers\Http\Controllers\ClientesController;
use App\Modules\Customers\Http\Controllers\V1\ClientesController as V1ClientesController;
use Illuminate\Support\Facades\Route;

Route::middleware('api-v1')->group(function (): void {
    Route::get('clientes', [V1ClientesController::class, 'index']);
    Route::post('clientes', [ClientesController::class, 'store']);
    Route::get('clientes/{id}', [ClientesController::class, 'show'])->whereUuid('id');
    Route::put('clientes/{id}', [ClientesController::class, 'update'])->whereUuid('id');
});

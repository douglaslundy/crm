<?php

declare(strict_types=1);

use App\Modules\Customers\Http\Controllers\ClientesController;
use Illuminate\Support\Facades\Route;

Route::middleware('empresa')->group(function (): void {
    Route::get('clientes', [ClientesController::class, 'index']);
    Route::post('clientes', [ClientesController::class, 'store']);
    Route::get('clientes/{id}', [ClientesController::class, 'show']);
    Route::put('clientes/{id}', [ClientesController::class, 'update']);
});

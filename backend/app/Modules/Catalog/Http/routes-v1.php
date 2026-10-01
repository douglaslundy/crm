<?php

declare(strict_types=1);

use App\Modules\Catalog\Http\Controllers\ProdutosController;
use App\Modules\Catalog\Http\Controllers\ServicosController;
use Illuminate\Support\Facades\Route;

Route::middleware('empresa')->group(function (): void {
    Route::get('produtos', [ProdutosController::class, 'index']);
    Route::post('produtos', [ProdutosController::class, 'store']);
    Route::get('produtos/{id}', [ProdutosController::class, 'show']);
    Route::put('produtos/{id}', [ProdutosController::class, 'update']);

    Route::get('servicos', [ServicosController::class, 'index']);
    Route::post('servicos', [ServicosController::class, 'store']);
    Route::get('servicos/{id}', [ServicosController::class, 'show']);
    Route::put('servicos/{id}', [ServicosController::class, 'update']);
});

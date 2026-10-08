<?php

declare(strict_types=1);

use App\Modules\Catalog\Http\Controllers\ProdutosController;
use App\Modules\Catalog\Http\Controllers\ServicosController;
use App\Modules\Catalog\Http\Controllers\V1\ProdutosController as V1ProdutosController;
use App\Modules\Catalog\Http\Controllers\V1\ServicosController as V1ServicosController;
use Illuminate\Support\Facades\Route;

Route::middleware('api-v1')->group(function (): void {
    Route::get('produtos', [V1ProdutosController::class, 'index']);
    Route::post('produtos', [ProdutosController::class, 'store']);
    Route::get('produtos/{id}', [ProdutosController::class, 'show'])->whereUuid('id');
    Route::put('produtos/{id}', [ProdutosController::class, 'update'])->whereUuid('id');

    Route::get('servicos', [V1ServicosController::class, 'index']);
    Route::post('servicos', [ServicosController::class, 'store']);
    Route::get('servicos/{id}', [ServicosController::class, 'show'])->whereUuid('id');
    Route::put('servicos/{id}', [ServicosController::class, 'update'])->whereUuid('id');
});

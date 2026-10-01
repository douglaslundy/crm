<?php

declare(strict_types=1);

use App\Modules\Catalog\Http\Controllers\ProdutosController;
use Illuminate\Support\Facades\Route;

Route::middleware('empresa')->group(function (): void {
    Route::get('produtos', [ProdutosController::class, 'index'])->name('app.produtos.index');
    Route::post('produtos', [ProdutosController::class, 'store'])->name('app.produtos.store');
    Route::get('produtos/{id}', [ProdutosController::class, 'show'])->name('app.produtos.show');
    Route::put('produtos/{id}', [ProdutosController::class, 'update'])->name('app.produtos.update');
});

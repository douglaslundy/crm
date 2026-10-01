<?php

declare(strict_types=1);

use App\Modules\Catalog\Http\Controllers\CategoriasFiscaisController;
use App\Modules\Catalog\Http\Controllers\ProdutosController;
use App\Modules\Catalog\Http\Controllers\ServicosController;
use Illuminate\Support\Facades\Route;

Route::middleware('empresa')->group(function (): void {
    Route::get('produtos', [ProdutosController::class, 'index'])->name('app.produtos.index');
    Route::post('produtos', [ProdutosController::class, 'store'])->name('app.produtos.store');
    Route::get('produtos/{id}', [ProdutosController::class, 'show'])->name('app.produtos.show');
    Route::put('produtos/{id}', [ProdutosController::class, 'update'])->name('app.produtos.update');

    Route::get('servicos', [ServicosController::class, 'index'])->name('app.servicos.index');
    Route::post('servicos', [ServicosController::class, 'store'])->name('app.servicos.store');
    Route::get('servicos/{id}', [ServicosController::class, 'show'])->name('app.servicos.show');
    Route::put('servicos/{id}', [ServicosController::class, 'update'])->name('app.servicos.update');

    Route::get('categorias-fiscais-padrao', [CategoriasFiscaisController::class, 'index'])->name('app.categorias-fiscais.index');
    Route::post('categorias-fiscais-padrao', [CategoriasFiscaisController::class, 'store'])->name('app.categorias-fiscais.store');
    Route::put('categorias-fiscais-padrao/{id}', [CategoriasFiscaisController::class, 'update'])->name('app.categorias-fiscais.update');
    Route::post('produtos/{produtoId}/aplicar-categoria/{categoriaId}', [CategoriasFiscaisController::class, 'aplicar'])->name('app.produtos.aplicar-categoria');
});

<?php

declare(strict_types=1);

use App\Modules\Fiscal\Http\Controllers\V1\EmpresaController;
use App\Modules\Fiscal\Http\Controllers\V1\NotasController;
use Illuminate\Support\Facades\Route;

Route::middleware('api-v1')->group(function (): void {
    Route::get('empresa', EmpresaController::class);

    Route::post('notas', [NotasController::class, 'store'])->middleware('idempotencia:obrigatoria');
});

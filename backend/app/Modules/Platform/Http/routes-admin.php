<?php

declare(strict_types=1);

use App\Modules\Platform\Http\Controllers\Admin\DesativarPlanoController;
use App\Modules\Platform\Http\Controllers\Admin\PlanosController;
use Illuminate\Support\Facades\Route;

Route::middleware('plataforma')->group(function (): void {
    Route::get('planos', [PlanosController::class, 'index']);
    Route::post('planos', [PlanosController::class, 'store']);
    Route::get('planos/{plano}', [PlanosController::class, 'show']);
    Route::put('planos/{plano}', [PlanosController::class, 'update']);
    Route::post('planos/{plano}/desativar', DesativarPlanoController::class);
});

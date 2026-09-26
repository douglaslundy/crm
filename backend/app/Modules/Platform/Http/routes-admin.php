<?php

declare(strict_types=1);

use App\Modules\Platform\Http\Controllers\Admin\DesativarPlanoController;
use App\Modules\Platform\Http\Controllers\Admin\EmpresasController;
use App\Modules\Platform\Http\Controllers\Admin\MetricasController;
use App\Modules\Platform\Http\Controllers\Admin\MudarSituacaoController;
use App\Modules\Platform\Http\Controllers\Admin\PlanosController;
use App\Modules\Platform\Http\Controllers\Admin\TrocarPlanoController;
use Illuminate\Support\Facades\Route;

Route::middleware('plataforma')->group(function (): void {
    Route::get('planos', [PlanosController::class, 'index']);
    Route::post('planos', [PlanosController::class, 'store']);
    Route::get('planos/{plano}', [PlanosController::class, 'show']);
    Route::put('planos/{plano}', [PlanosController::class, 'update']);
    Route::post('planos/{plano}/desativar', DesativarPlanoController::class);

    Route::get('empresas', [EmpresasController::class, 'index']);
    Route::get('empresas/{tenant}', [EmpresasController::class, 'show']);
    Route::post('empresas/{tenant}/situacao', MudarSituacaoController::class);
    Route::post('empresas/{tenant}/plano', TrocarPlanoController::class);
    Route::get('metricas', MetricasController::class);
});

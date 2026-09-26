<?php

declare(strict_types=1);

use App\Modules\Identity\Http\Controllers\EsqueciSenhaController;
use App\Modules\Identity\Http\Controllers\LoginController;
use App\Modules\Identity\Http\Controllers\LogoutController;
use App\Modules\Identity\Http\Controllers\MeController;
use App\Modules\Identity\Http\Controllers\RedefinirSenhaController;
use App\Modules\Identity\Http\Middleware\GarantirUsuarioAtivo;
use App\Modules\Tenancy\Http\Middleware\DefinirTenantDoUsuario;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->group(function (): void {
    Route::post('login', LoginController::class);
    Route::post('esqueci-senha', EsqueciSenhaController::class)->middleware('throttle:3,60');
    Route::post('redefinir-senha', RedefinirSenhaController::class)->middleware('throttle:10,60');

    Route::middleware(['auth:sanctum', GarantirUsuarioAtivo::class, DefinirTenantDoUsuario::class])->group(function (): void {
        Route::post('logout', LogoutController::class);
        Route::get('me', MeController::class);
    });
});

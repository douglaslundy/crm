<?php

declare(strict_types=1);

use App\Modules\Identity\Http\Controllers\AceitarConviteController;
use App\Modules\Identity\Http\Controllers\DesativarUsuarioController;
use App\Modules\Identity\Http\Controllers\EsqueciSenhaController;
use App\Modules\Identity\Http\Controllers\LoginController;
use App\Modules\Identity\Http\Controllers\LogoutController;
use App\Modules\Identity\Http\Controllers\MeController;
use App\Modules\Identity\Http\Controllers\ReativarUsuarioController;
use App\Modules\Identity\Http\Controllers\RedefinirSenhaController;
use App\Modules\Identity\Http\Controllers\UsuariosController;
use App\Modules\Identity\Http\Middleware\GarantirUsuarioAtivo;
use App\Modules\Tenancy\Http\Middleware\DefinirTenantDoUsuario;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->group(function (): void {
    Route::post('login', LoginController::class);
    Route::post('esqueci-senha', EsqueciSenhaController::class)->middleware('throttle:3,60');
    Route::post('redefinir-senha', RedefinirSenhaController::class)->middleware('throttle:10,60');
    Route::post('aceitar-convite', AceitarConviteController::class)->middleware('throttle:10,60');

    Route::middleware(['auth:sanctum', GarantirUsuarioAtivo::class, DefinirTenantDoUsuario::class])->group(function (): void {
        Route::post('logout', LogoutController::class);
        Route::get('me', MeController::class);
    });
});

Route::middleware('empresa')->prefix('usuarios')->group(function (): void {
    Route::get('/', [UsuariosController::class, 'index']);
    Route::post('/', [UsuariosController::class, 'store']);
    Route::put('{id}', [UsuariosController::class, 'update'])->whereUuid('id');
    Route::post('{id}/desativar', DesativarUsuarioController::class)->whereUuid('id');
    Route::post('{id}/reativar', ReativarUsuarioController::class)->whereUuid('id');
});

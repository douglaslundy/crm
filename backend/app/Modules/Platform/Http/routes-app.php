<?php

declare(strict_types=1);

use App\Modules\Platform\Http\Controllers\AssinaturaController;
use Illuminate\Support\Facades\Route;

Route::middleware('empresa')->group(function (): void {
    Route::get('assinatura', AssinaturaController::class)->name('app.assinatura');
});

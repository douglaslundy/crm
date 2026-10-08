<?php

declare(strict_types=1);

use App\Modules\Platform\Http\Controllers\V1\ConsumoController;
use Illuminate\Support\Facades\Route;

Route::middleware('api-v1')->group(function (): void {
    Route::get('consumo', ConsumoController::class);
});

<?php

declare(strict_types=1);

use App\Modules\Billing\Http\Controllers\AssinaturaDeCobrancaController;
use Illuminate\Support\Facades\Route;

Route::middleware('empresa')->group(function (): void {
    Route::post('assinatura/checkout', [AssinaturaDeCobrancaController::class, 'checkout'])->name('app.assinatura.checkout');
    Route::get('assinatura/cobrancas', [AssinaturaDeCobrancaController::class, 'cobrancas'])->name('app.assinatura.cobrancas');
    Route::post('assinatura/cobrancas/{cobranca}/pagar', [AssinaturaDeCobrancaController::class, 'pagar'])->name('app.assinatura.cobrancas.pagar');
    Route::post('assinatura/trocar-plano', [AssinaturaDeCobrancaController::class, 'trocarPlano'])->name('app.assinatura.trocar-plano');
    Route::delete('assinatura/trocar-plano', [AssinaturaDeCobrancaController::class, 'cancelarTroca'])->name('app.assinatura.cancelar-troca');
    Route::post('assinatura/cancelar', [AssinaturaDeCobrancaController::class, 'cancelar'])->name('app.assinatura.cancelar');
});

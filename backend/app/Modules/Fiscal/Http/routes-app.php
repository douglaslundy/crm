<?php

declare(strict_types=1);

use App\Modules\Fiscal\Http\Controllers\CertificadoController;
use App\Modules\Fiscal\Http\Controllers\ConfiguracaoFiscalController;
use App\Modules\Fiscal\Http\Controllers\ConsultarCepController;
use App\Modules\Fiscal\Http\Controllers\EmitenteController;
use Illuminate\Support\Facades\Route;

Route::middleware('empresa')->group(function (): void {
    Route::get('emitente', [EmitenteController::class, 'show'])->name('app.emitente.show');
    Route::put('emitente/empresa', [EmitenteController::class, 'atualizarEmpresa'])->name('app.emitente.empresa');
    Route::put('emitente/fiscal', [EmitenteController::class, 'atualizarFiscal'])->name('app.emitente.fiscal');
    Route::get('emitente/cep/{cep}', ConsultarCepController::class)->name('app.emitente.cep');
    Route::post('emitente/certificado', [CertificadoController::class, 'store'])->name('app.emitente.certificado');
    Route::put('emitente/csc', [ConfiguracaoFiscalController::class, 'atualizarCsc'])->name('app.emitente.csc');
    Route::put('emitente/series/{modelo}', [ConfiguracaoFiscalController::class, 'atualizarSerie'])->whereIn('modelo', ['NFE', 'NFCE', 'DPS'])->name('app.emitente.series');
    Route::post('emitente/ambiente/producao', [ConfiguracaoFiscalController::class, 'confirmarProducao'])->name('app.emitente.producao');
});

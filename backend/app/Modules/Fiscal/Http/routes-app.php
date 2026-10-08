<?php

declare(strict_types=1);

use App\Modules\Fiscal\Http\Controllers\CertificadoController;
use App\Modules\Fiscal\Http\Controllers\ConfiguracaoFiscalController;
use App\Modules\Fiscal\Http\Controllers\ConsultarCepController;
use App\Modules\Fiscal\Http\Controllers\DashboardFiscalController;
use App\Modules\Fiscal\Http\Controllers\EmitenteController;
use App\Modules\Fiscal\Http\Controllers\InutilizacaoController;
use App\Modules\Fiscal\Http\Controllers\NotaController;
use App\Modules\Fiscal\Http\Controllers\VendaController;
use App\Modules\Fiscal\Http\Controllers\WebhooksController;
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

    Route::get('dashboard/fiscal', DashboardFiscalController::class)->name('app.dashboard.fiscal');

    Route::get('notas', [NotaController::class, 'index'])->name('app.notas.index');
    Route::post('notas', [NotaController::class, 'store'])->name('app.notas.store');
    Route::get('notas/{nota}', [NotaController::class, 'show'])->name('app.notas.show');
    Route::put('notas/{nota}', [NotaController::class, 'update'])->name('app.notas.update');
    Route::delete('notas/{nota}', [NotaController::class, 'destroy'])->name('app.notas.destroy');
    Route::get('notas/{nota}/status', [NotaController::class, 'status'])->name('app.notas.status');
    Route::post('notas/{nota}/emitir', [NotaController::class, 'emitir'])->name('app.notas.emitir');
    Route::get('notas/{nota}/xml', [NotaController::class, 'xml'])->name('app.notas.xml');
    Route::post('notas/{nota}/retransmitir', [NotaController::class, 'retransmitir'])->name('app.notas.retransmitir');
    Route::post('notas/{nota}/cancelar', [NotaController::class, 'cancelar'])->name('app.notas.cancelar');
    Route::get('notas/{nota}/xml-cancelamento', [NotaController::class, 'xmlCancelamento'])->name('app.notas.xml-cancelamento');
    Route::get('notas/{nota}/pdf', [NotaController::class, 'pdf'])->name('app.notas.pdf');
    Route::post('vendas', [VendaController::class, 'store'])->name('app.vendas.store');
    Route::get('vendas/{venda}', [VendaController::class, 'show'])->name('app.vendas.show');
    Route::put('vendas/{venda}', [VendaController::class, 'update'])->name('app.vendas.update');
    Route::delete('vendas/{venda}', [VendaController::class, 'destroy'])->name('app.vendas.destroy');
    Route::post('vendas/{venda}/emitir', [VendaController::class, 'emitir'])->name('app.vendas.emitir');
    Route::get('inutilizacoes', [InutilizacaoController::class, 'index'])->name('app.inutilizacoes.index');
    Route::post('inutilizacoes', [InutilizacaoController::class, 'store'])->name('app.inutilizacoes.store');

    // Entregas antes de webhooks/{id}; {id} é sempre uuid.
    Route::get('webhooks/entregas/{id}', [WebhooksController::class, 'entrega'])->whereUuid('id')->name('app.webhooks.entregas.show');
    Route::post('webhooks/entregas/{id}/reenviar', [WebhooksController::class, 'reenviar'])->whereUuid('id')->name('app.webhooks.entregas.reenviar');
    Route::get('webhooks', [WebhooksController::class, 'index'])->name('app.webhooks.index');
    Route::post('webhooks', [WebhooksController::class, 'store'])->name('app.webhooks.store');
    Route::get('webhooks/{id}', [WebhooksController::class, 'show'])->whereUuid('id')->name('app.webhooks.show');
    Route::put('webhooks/{id}', [WebhooksController::class, 'update'])->whereUuid('id')->name('app.webhooks.update');
    Route::delete('webhooks/{id}', [WebhooksController::class, 'destroy'])->whereUuid('id')->name('app.webhooks.destroy');
    Route::get('webhooks/{id}/entregas', [WebhooksController::class, 'entregas'])->whereUuid('id')->name('app.webhooks.entregas.index');
    Route::post('webhooks/{id}/testar', [WebhooksController::class, 'testar'])->whereUuid('id')->name('app.webhooks.testar');
});

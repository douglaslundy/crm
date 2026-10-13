<?php

declare(strict_types=1);

use App\Modules\Crm\Http\Controllers\PropostaPublicaController;
use Illuminate\Support\Facades\Route;

Route::get('propostas/{token}', [PropostaPublicaController::class, 'show'])->name('public.propostas.show');
Route::get('propostas/{token}/pdf', [PropostaPublicaController::class, 'pdf'])->name('public.propostas.pdf');
Route::post('propostas/{token}/aceitar', [PropostaPublicaController::class, 'aceitar'])->name('public.propostas.aceitar');
Route::post('propostas/{token}/recusar', [PropostaPublicaController::class, 'recusar'])->name('public.propostas.recusar');

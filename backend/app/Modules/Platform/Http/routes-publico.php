<?php

declare(strict_types=1);

use App\Modules\Platform\Http\Controllers\Publico\CadastroController;
use App\Modules\Platform\Http\Controllers\Publico\ConsultarCnpjController;
use App\Modules\Platform\Http\Controllers\Publico\ContatoSiteController;
use App\Modules\Platform\Http\Controllers\Publico\PlanosPublicosController;
use Illuminate\Support\Facades\Route;

Route::get('planos', PlanosPublicosController::class);
Route::get('cnpj/{cnpj}', ConsultarCnpjController::class)->middleware('throttle:10,1,cnpj');
Route::post('cadastro', CadastroController::class)->middleware('throttle:5,60,cadastro');
Route::get('contato/desafio', [ContatoSiteController::class, 'desafio'])->middleware('throttle:30,1,contato-desafio');
Route::post('contato', [ContatoSiteController::class, 'enviar'])->middleware('throttle:5,10,contato');

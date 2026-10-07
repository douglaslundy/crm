<?php

declare(strict_types=1);

use App\Modules\Billing\Http\Controllers\WebhookDoMercadoPagoController;
use Illuminate\Support\Facades\Route;

Route::post('mercadopago', WebhookDoMercadoPagoController::class)->name('webhooks.mercadopago');

<?php

declare(strict_types=1);

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('assinaturas:expirar-testes')->dailyAt('00:10')->timezone('America/Sao_Paulo')
    ->withoutOverlapping()->onOneServer();

Schedule::command('fiscal:reconciliar-processando')->everyFifteenMinutes()
    ->withoutOverlapping()->onOneServer();

Schedule::command('fiscal:retransmitir-contingencia')->hourly()
    ->withoutOverlapping()->onOneServer();

Schedule::command('billing:gerar-boletos')->dailyAt('00:20')->timezone('America/Sao_Paulo')
    ->withoutOverlapping()->onOneServer();

Schedule::command('billing:aplicar-inadimplencia')->dailyAt('00:30')->timezone('America/Sao_Paulo')
    ->withoutOverlapping()->onOneServer();

Schedule::command('billing:fechar-excedente')->monthlyOn(1, '00:40')->timezone('America/Sao_Paulo')
    ->withoutOverlapping()->onOneServer();

Schedule::command('billing:aplicar-trocas')->dailyAt('00:25')->timezone('America/Sao_Paulo')
    ->withoutOverlapping()->onOneServer();

Schedule::command('api:limpar-idempotencias')->dailyAt('00:50')->timezone('America/Sao_Paulo')
    ->withoutOverlapping()->onOneServer();

Schedule::command('webhooks:reenviar-pendentes')->everyMinute()
    ->withoutOverlapping()->onOneServer();

Schedule::command('webhooks:limpar')->dailyAt('00:55')->timezone('America/Sao_Paulo')
    ->withoutOverlapping()->onOneServer();

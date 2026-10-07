<?php

declare(strict_types=1);

return [
    'mercadopago' => [
        'access_token' => env('MERCADOPAGO_ACCESS_TOKEN'),
        'webhook_secret' => env('MERCADOPAGO_WEBHOOK_SECRET'),
        'public_key' => env('MERCADOPAGO_PUBLIC_KEY'),
        'base_url' => env('MERCADOPAGO_BASE_URL', 'https://api.mercadopago.com'),
    ],

    // Dias depois do vencimento para o tenant ir a INADIMPLENTE e a SUSPENSA.
    'carencia_inadimplente_dias' => (int) env('BILLING_CARENCIA_INADIMPLENTE_DIAS', 1),
    'carencia_suspensao_dias' => (int) env('BILLING_CARENCIA_SUSPENSAO_DIAS', 10),

    // Quantos dias antes do fim do período pago o boleto do próximo ciclo é gerado.
    'antecedencia_boleto_dias' => 5,
    // Prazo do boleto da primeira cobrança.
    'dias_primeiro_boleto' => 3,
    // Diferença máxima entre o `ts` assinado do webhook e a hora do servidor.
    'tolerancia_webhook_segundos' => 600,
];

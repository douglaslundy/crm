<?php

declare(strict_types=1);

return [
    // Base do campo `type` dos erros (RFC 9457) do /api/v1; a página de cada erro vem com a documentação (F6c).
    'erros_base_url' => rtrim((string) env('API_ERROS_BASE_URL', 'https://docs.example.com/erros'), '/'),
];

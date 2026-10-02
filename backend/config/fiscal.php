<?php

declare(strict_types=1);

return [
    /** Versão do aplicativo emissor enviada em `verProc` (máx. 20 caracteres). */
    'ver_proc' => env('FISCAL_VER_PROC', 'plataforma-1.0'),

    /** Nota PROCESSANDO sem resposta há mais que isso vai para ERRO na reconciliação. */
    'minutos_para_nunca_enviada' => 10,

    /** Quantas vezes pular número ocupado por nota cancelada (cStat 539). */
    'tentativas_numero_ocupado' => 5,
];

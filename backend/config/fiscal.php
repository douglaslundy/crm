<?php

declare(strict_types=1);

return [
    /** Versão do aplicativo emissor enviada em `verProc` (máx. 20 caracteres). */
    'ver_proc' => env('FISCAL_VER_PROC', 'plataforma-1.0'),

    /** Nota PROCESSANDO sem resposta há mais que isso vai para ERRO na reconciliação. */
    'minutos_para_nunca_enviada' => 10,

    /** Quantas vezes pular número ocupado por nota cancelada (cStat 539). */
    'tentativas_numero_ocupado' => 5,

    /**
     * Prazo para transmitir uma NFC-e emitida em contingência offline. O valor legal varia por UF e não foi
     * confirmado: o padrão de 24 h é conservador (o alerta aparece cedo). Ajuste conforme a SEFAZ da UF.
     */
    'contingencia_prazo_horas' => (int) env('FISCAL_CONTINGENCIA_PRAZO_HORAS', 24),

    /** O painel alerta quando faltam menos horas que isto para o limite da contingência. */
    'contingencia_alerta_horas' => (int) env('FISCAL_CONTINGENCIA_ALERTA_HORAS', 6),

    /** Prazo legal do EPEC da NF-e para transmitir o XML normal: 7 dias. */
    'epec_prazo_horas' => (int) env('FISCAL_EPEC_PRAZO_HORAS', 168),

    /** O painel alerta quando faltam menos horas que isto para o limite do EPEC. */
    'epec_alerta_horas' => (int) env('FISCAL_EPEC_ALERTA_HORAS', 48),

    /** Só para teste de carga (exige APP_AMBIENTE=staging, senão o boot aborta). Troca o envio à SEFAZ por uma resposta sintética. */
    'emissor_simulado' => (bool) env('FISCAL_EMISSOR_SIMULADO', false),

    'emissor_simulado_latencia_ms' => (int) env('FISCAL_EMISSOR_SIMULADO_LATENCIA_MS', 150),
];

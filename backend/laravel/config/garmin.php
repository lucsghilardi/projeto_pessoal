<?php

// Integração com o Garmin Connect via sidecar Python (docker-compose: `garmin`).
// Não existe cliente PHP mantido para a API do Garmin, e a lib Python cuida da
// renovação/rotação dos tokens — por isso o Laravel só consome HTTP interno.
return [

    // Desliga a sincronização sem remover o job do schedule.
    'ativo' => (bool) env('GARMIN_ATIVO', false),

    'base_url' => env('GARMIN_SIDECAR_URL', 'http://garmin:8000'),
    'token' => env('GARMIN_SIDECAR_TOKEN'),

    // Um sidecar = uma conta Garmin. Define quem recebe o que for importado.
    'user_email' => env('GARMIN_USER_EMAIL', env('ADMIN_EMAIL')),

    // Janela re-sincronizada a cada execução: o relógio às vezes sobe a
    // atividade com atraso. O dedupe por garmin_activity_id evita duplicar.
    'dias_janela' => (int) env('GARMIN_DIAS_JANELA', 7),

    'timeout' => (int) env('GARMIN_TIMEOUT', 60),

];

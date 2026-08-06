<?php

// Módulo Saúde: check-in de suplementos, treinos A/B e acompanhamento de peso.
return [

    // Fuso usado para "hoje" nos check-ins e lembretes (o app roda em UTC).
    'timezone' => env('SAUDE_TIMEZONE', env('WHATSAPP_TIMEZONE', 'America/Sao_Paulo')),

    'lembretes' => [
        // Desliga os lembretes de suplementos por WhatsApp sem remover o job.
        'ativo' => (bool) env('SAUDE_LEMBRETES_ATIVO', true),
    ],

];

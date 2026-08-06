<?php

// Módulo Saúde: check-in de suplementos, treinos A/B e acompanhamento de peso.
return [

    // Fuso usado para "hoje" nos check-ins e lembretes (o app roda em UTC).
    'timezone' => env('SAUDE_TIMEZONE', env('WHATSAPP_TIMEZONE', 'America/Sao_Paulo')),

    'lembretes' => [
        // Desliga os lembretes de suplementos por WhatsApp sem remover o job.
        'ativo' => (bool) env('SAUDE_LEMBRETES_ATIVO', true),
    ],

    'nutricao' => [
        // Modelo de IA para análise de refeições (foto/texto do WhatsApp).
        'model' => env('ANTHROPIC_SAUDE_MODEL', 'claude-sonnet-5'),
        // Proteína alvo em déficit (preservação muscular).
        'proteina_g_por_kg' => 1.8,
        // Déficit diário quando a meta não tem data alvo (~0,5 kg/semana).
        'deficit_padrao_kcal' => 500,
        // Travas de segurança: déficit nunca passa deste teto (nem de 25% do TDEE)
        // e as calorias nunca ficam abaixo do piso por sexo.
        'deficit_maximo_kcal' => 1000,
        'calorias_minimas' => ['M' => 1500, 'F' => 1200],
    ],

];

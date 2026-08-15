<?php

// Módulo Financeiro: contas a pagar/receber, cartões de crédito e faturas.
return [

    // Fuso usado para decidir "hoje" nas datas-calendário do módulo — em especial
    // se a fatura do cartão já fechou (o app roda em UTC, mas as datas são
    // digitadas no relógio local do usuário).
    'timezone' => env('FINANCE_TIMEZONE', env('WHATSAPP_TIMEZONE', 'America/Sao_Paulo')),

];

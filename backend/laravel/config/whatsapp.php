<?php

// Módulo WhatsApp: monitoramento de conversas via Evolution API + análises por
// IA (Claude). Ver docker-compose (serviços evolution/queue/scheduler).
return [

    'evolution' => [
        // Endereço da Evolution API na rede interna do compose.
        'base_url' => env('EVOLUTION_BASE_URL', 'http://evolution:8080'),
        // Chave global (AUTHENTICATION_API_KEY do container evolution).
        'api_key' => env('EVOLUTION_API_KEY'),
    ],

    'webhook' => [
        // URL que a Evolution chama para entregar eventos. Interna ao compose;
        // o token abaixo é anexado à query string e conferido no controller.
        'url' => env('WHATSAPP_WEBHOOK_URL', rtrim((string) env('APP_URL'), '/').'/api/whatsapp/webhook/evolution'),
        'token' => env('WHATSAPP_WEBHOOK_TOKEN'),
    ],

    'ia' => [
        'model' => env('ANTHROPIC_WHATSAPP_MODEL', 'claude-sonnet-5'),
    ],

    'relatorio' => [
        // Horários dos jobs agendados (routes/console.php).
        'hora_diario' => env('WHATSAPP_RELATORIO_HORA', '19:00'),
        'hora_matinal' => env('WHATSAPP_RESUMO_HORA', '07:30'),
        'timezone' => env('WHATSAPP_TIMEZONE', 'America/Sao_Paulo'),
        // Uma conversa conta como "aguardando resposta" depois deste tempo
        // sem retorno seu (score de atenção e relatórios).
        'horas_aguardando' => (int) env('WHATSAPP_HORAS_AGUARDANDO', 3),
    ],

    'gtd' => [
        // Mensagens enviadas para você mesmo viram tarefas neste projeto/coluna
        // do kanban (criados automaticamente se não existirem).
        'projeto' => env('WHATSAPP_GTD_PROJETO', 'WhatsApp'),
        'coluna' => env('WHATSAPP_GTD_COLUNA', 'Caixa de entrada'),
    ],

    // Assistente do chat-consigo-mesmo: propõe e só grava depois de confirmado.
    'conversa' => [
        // Quanto tempo uma pergunta pendente continua valendo. Depois disso,
        // a próxima mensagem é tratada como assunto novo.
        'ttl_minutos' => (int) env('WHATSAPP_CONVERSA_TTL', 30),
        // Janela curta do estado 'processando': se o job morrer sem destravar,
        // o chat volta a responder em vez de ficar preso em "aguarde".
        'ttl_processando_minutos' => (int) env('WHATSAPP_CONVERSA_TTL_PROCESSANDO', 2),
        // Respostas seguidas que não deram para interpretar antes de desistir.
        'max_tentativas' => (int) env('WHATSAPP_CONVERSA_MAX_TENTATIVAS', 3),
        // Quanto tempo o hash de uma mensagem enviada fica registrado para
        // reconhecer o próprio eco vindo do webhook (anti-loop).
        'eco_ttl_minutos' => (int) env('WHATSAPP_CONVERSA_ECO_TTL', 5),
        // Teto de mensagens enviadas por minuto numa instância. Disjuntor
        // contra loop: sem isso, um ciclo queima crédito da Anthropic.
        'max_envios_por_minuto' => (int) env('WHATSAPP_CONVERSA_MAX_ENVIOS', 25),
        // Cinto extra de anti-loop: texto começando com um destes nunca é
        // tratado como entrada sua. Toda mensagem do bot começa com um deles —
        // e nenhum deles é emoji de aceite/recusa (👍, 👌, ❌...), que você pode
        // querer mandar como resposta.
        'prefixos_bot' => [
            '✅', '📋', '🍽️', '🧾', '📷', '📊', '🎯', '⚠️',
            '🤷', '🤔', '⏳', '📄', '🙉', '🏦', '📌', '🗑️', '✏️',
        ],
    ],

];

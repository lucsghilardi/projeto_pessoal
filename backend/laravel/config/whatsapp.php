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

];

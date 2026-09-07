<?php

// Sem este arquivo vale o default do framework, que responde
// `Access-Control-Allow-Origin: *` em todo /api/*. Não é explorável hoje (o
// nginx do container não é publicado e o token vai em cookie httpOnly no
// domínio do Next), mas devolver "*" é convite para o dia em que a API for
// exposta direto. A origem é a do painel — front e back moram no mesmo domínio.
return [

    'paths' => ['api/*'],

    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],

    'allowed_origins' => array_values(array_filter([
        rtrim((string) env('APP_URL', ''), '/'),
    ])),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['Accept', 'Authorization', 'Content-Type', 'X-Requested-With'],

    'exposed_headers' => [],

    'max_age' => 0,

    // O painel autentica por Bearer montado no proxy do Next, no servidor.
    // Nenhum navegador precisa mandar credencial cross-origin para cá.
    'supports_credentials' => false,

];

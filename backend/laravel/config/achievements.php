<?php

/*
|--------------------------------------------------------------------------
| Conquistas (badges) do "Quest Board"
|--------------------------------------------------------------------------
|
| Cada conquista é avaliada pelo GamificationService quando uma tarefa é
| concluída. A definição vive aqui (chave, rótulo, descrição, ícone); apenas
| o desbloqueio é persistido na tabela user_achievements.
|
| icon = nome de um ícone lucide-react usado no frontend.
|
*/

return [
    'first_task' => [
        'label' => 'Primeira Missão',
        'description' => 'Conclua sua primeira tarefa.',
        'icon' => 'Sparkles',
    ],
    'ten_tasks' => [
        'label' => 'Pegando o Ritmo',
        'description' => 'Conclua 10 tarefas no total.',
        'icon' => 'Rocket',
    ],
    'fifty_tasks' => [
        'label' => 'Centurião',
        'description' => 'Conclua 50 tarefas no total.',
        'icon' => 'Medal',
    ],
    'maratonista' => [
        'label' => 'Maratonista',
        'description' => 'Conclua 10 tarefas em um único dia.',
        'icon' => 'Flame',
    ],
    'domador_urgencias' => [
        'label' => 'Domador de Urgências',
        'description' => 'Conclua 5 tarefas urgentes dentro do prazo.',
        'icon' => 'Zap',
    ],
    'semana_perfeita' => [
        'label' => 'Semana Perfeita',
        'description' => 'Mantenha uma sequência de 7 dias.',
        'icon' => 'CalendarCheck',
    ],
    'nivel_5' => [
        'label' => 'Veterano',
        'description' => 'Alcance o nível 5.',
        'icon' => 'Shield',
    ],
    'nivel_10' => [
        'label' => 'Lenda',
        'description' => 'Alcance o nível 10.',
        'icon' => 'Crown',
    ],
];

<?php

namespace App\Services\Whatsapp;

use App\Models\Project;
use App\Models\Task;
use App\Models\TaskColumn;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Ponte entre o módulo WhatsApp e o kanban de tarefas: cria tarefas a partir
 * de sugestões da IA e de notas GTD, garantindo o projeto/coluna padrão.
 */
class WhatsappTaskBridge
{
    /**
     * @param  array{titulo:string,descricao?:string|null,prioridade?:string,due_date?:string|null}  $dados
     */
    public function criarTarefa(User $user, array $dados, ?int $projectId = null, ?int $columnId = null): Task
    {
        return DB::transaction(function () use ($user, $dados, $projectId, $columnId) {
            $coluna = $this->resolverColuna($user, $projectId, $columnId);

            $posicao = (int) Task::where('task_column_id', $coluna->id)->max('position') + 1;

            return Task::create([
                'user_id' => $user->id,
                'project_id' => $coluna->project_id,
                'task_column_id' => $coluna->id,
                'title' => $dados['titulo'],
                'description' => $dados['descricao'] ?? null,
                'priority' => $dados['prioridade'] ?? 'medium',
                'due_date' => $dados['due_date'] ?? null,
                'position' => $posicao,
            ]);
        });
    }

    /**
     * Resolve a coluna destino: a indicada, ou a primeira do projeto indicado,
     * ou a coluna padrão do projeto "WhatsApp" (criados sob demanda).
     */
    private function resolverColuna(User $user, ?int $projectId, ?int $columnId): TaskColumn
    {
        if ($columnId !== null) {
            $coluna = TaskColumn::where('user_id', $user->id)->find($columnId);
            if ($coluna !== null) {
                return $coluna;
            }
        }

        if ($projectId !== null) {
            $projeto = Project::where('user_id', $user->id)->find($projectId);
            if ($projeto !== null) {
                $coluna = $projeto->columns()->first();
                if ($coluna !== null) {
                    return $coluna;
                }
            }
        }

        return $this->colunaPadrao($user);
    }

    private function colunaPadrao(User $user): TaskColumn
    {
        $projeto = Project::firstOrCreate(
            ['user_id' => $user->id, 'name' => (string) config('whatsapp.gtd.projeto')],
            ['color' => '#22c55e', 'description' => 'Tarefas capturadas pelo WhatsApp (notas GTD e sugestões da IA).'],
        );

        $coluna = $projeto->columns()->orderBy('position')->first();
        if ($coluna !== null) {
            return $coluna;
        }

        return TaskColumn::create([
            'user_id' => $user->id,
            'project_id' => $projeto->id,
            'name' => (string) config('whatsapp.gtd.coluna'),
            'position' => 0,
        ]);
    }
}

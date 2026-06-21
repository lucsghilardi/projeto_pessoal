"use client";

import { useCallback, useEffect, useMemo, useState } from "react";
import Link from "next/link";
import { useParams, useRouter } from "next/navigation";
import { ArrowLeft, Pencil, Plus } from "lucide-react";

import { DashboardPageLoader } from "@/components/dashboard/page-loader";
import { BoardColumn, KanbanBoard } from "@/components/tarefas/kanban-board";
import { ColumnSheet } from "@/components/tarefas/column-sheet";
import { fireConfetti } from "@/components/tarefas/confetti";
import { GamificationPanel } from "@/components/tarefas/gamification-panel";
import { ProjectSheet } from "@/components/tarefas/project-sheet";
import { TaskSheet } from "@/components/tarefas/task-sheet";
import { useTimer } from "@/components/tarefas/timer-context";
import { Button } from "@/components/ui/button";
import { appToast } from "@/lib/toast";
import {
  deleteColumn,
  getGamification,
  getProjectBoard,
  moveTask,
  reorderColumns,
} from "@/services/api";
import { ApiError } from "@/services/apiError";
import type { GamificationSummary } from "@/types/Gamification";
import type { Project, Task, TaskColumn } from "@/types/Task";

export default function ProjectBoardPage() {
  const params = useParams<{ projectId: string }>();
  const router = useRouter();
  const projectId = Number(params.projectId);

  const [project, setProject] = useState<Project | null>(null);
  const [columns, setColumns] = useState<BoardColumn[]>([]);
  const [gamification, setGamification] = useState<GamificationSummary | null>(null);
  const [loading, setLoading] = useState(true);

  const [taskSheetOpen, setTaskSheetOpen] = useState(false);
  const [editingTask, setEditingTask] = useState<Task | null>(null);
  const [defaultColumnId, setDefaultColumnId] = useState<number | null>(null);

  const [columnSheetOpen, setColumnSheetOpen] = useState(false);
  const [editingColumn, setEditingColumn] = useState<TaskColumn | null>(null);

  const [projectSheetOpen, setProjectSheetOpen] = useState(false);

  const { subscribeStopped } = useTimer();

  const updateTaskTracked = useCallback((taskId: number, tracked: number) => {
    setColumns((prev) =>
      prev.map((column) => ({
        ...column,
        tasks: column.tasks.map((task) =>
          task.id === taskId ? { ...task, tracked_seconds: tracked } : task,
        ),
      })),
    );
  }, []);

  // Ao parar um cronômetro, soma a duração ao tempo acumulado da tarefa.
  useEffect(
    () =>
      subscribeStopped((entry) => {
        if (entry.task_id == null) {
          return;
        }
        setColumns((prev) =>
          prev.map((column) => ({
            ...column,
            tasks: column.tasks.map((task) =>
              task.id === entry.task_id
                ? {
                    ...task,
                    tracked_seconds: (task.tracked_seconds ?? 0) + entry.duration_seconds,
                  }
                : task,
            ),
          })),
        );
      }),
    [subscribeStopped],
  );

  const loadBoard = useCallback(async () => {
    try {
      const [board, gami] = await Promise.all([
        getProjectBoard(projectId),
        getGamification(),
      ]);
      setProject(board);
      setColumns(board.columns.map((column) => ({ ...column, tasks: column.tasks ?? [] })));
      setGamification(gami);
    } catch (error) {
      appToast.error(
        error instanceof ApiError ? error.message : "Não foi possível carregar o projeto.",
      );
      if (error instanceof ApiError && (error.status === 403 || error.status === 404)) {
        router.replace("/dashboard/tarefas");
      }
    } finally {
      setLoading(false);
    }
  }, [projectId, router]);

  useEffect(() => {
    loadBoard();
  }, [loadBoard]);

  const refreshGamification = useCallback(async () => {
    try {
      setGamification(await getGamification());
    } catch {
      // silencioso: o painel apenas não atualiza
    }
  }, []);

  const handleMovePersist = useCallback(
    async (taskId: number, columnId: number, position: number) => {
      try {
        const result = await moveTask(taskId, {
          task_column_id: columnId,
          position,
        });

        if (result.xp_gained > 0) {
          fireConfetti();
          appToast.success(`Tarefa concluída! +${result.xp_gained} XP 🎉`);
        }

        setGamification(result.gamification);
        setColumns((prev) =>
          prev.map((column) => ({
            ...column,
            tasks: column.tasks.map((task) =>
              task.id === taskId ? { ...task, ...result.task } : task,
            ),
          })),
        );
      } catch (error) {
        appToast.error(
          error instanceof ApiError ? error.message : "Não foi possível mover a tarefa.",
        );
        loadBoard();
      }
    },
    [loadBoard],
  );

  function openCreateTask(columnId: number) {
    setEditingTask(null);
    setDefaultColumnId(columnId);
    setTaskSheetOpen(true);
  }

  function openEditTask(task: Task) {
    setEditingTask(task);
    setDefaultColumnId(null);
    setTaskSheetOpen(true);
  }

  function handleTaskCreated(task: Task) {
    setColumns((prev) =>
      prev.map((column) =>
        column.id === task.task_column_id
          ? { ...column, tasks: [...column.tasks, task] }
          : column,
      ),
    );
    if (task.completed_at) {
      refreshGamification();
    }
  }

  function handleTaskUpdated(task: Task) {
    setColumns((prev) =>
      prev.map((column) => ({
        ...column,
        tasks: column.tasks.map((current) =>
          current.id === task.id ? { ...current, ...task } : current,
        ),
      })),
    );
  }

  function handleTaskDeleted(taskId: number) {
    setColumns((prev) =>
      prev.map((column) => ({
        ...column,
        tasks: column.tasks.filter((task) => task.id !== taskId),
      })),
    );
  }

  function openCreateColumn() {
    setEditingColumn(null);
    setColumnSheetOpen(true);
  }

  function openEditColumn(column: TaskColumn) {
    setEditingColumn(column);
    setColumnSheetOpen(true);
  }

  function handleColumnCreated(column: TaskColumn) {
    setColumns((prev) => [...prev, { ...column, tasks: [] }]);
  }

  function handleColumnUpdated(column: TaskColumn) {
    setColumns((prev) =>
      prev.map((current) =>
        current.id === column.id ? { ...current, ...column, tasks: current.tasks } : current,
      ),
    );
  }

  async function handleColumnDelete(column: TaskColumn) {
    const target = columns.find((current) => current.id === column.id);
    if (target && target.tasks.length > 0) {
      appToast.error("Mova ou exclua as tarefas desta coluna antes de removê-la.");
      return;
    }

    try {
      await deleteColumn(column.id);
      setColumns((prev) => prev.filter((current) => current.id !== column.id));
      appToast.success("Coluna removida.");
    } catch (error) {
      appToast.error(
        error instanceof ApiError ? error.message : "Não foi possível remover a coluna.",
      );
    }
  }

  async function handleColumnMove(column: TaskColumn, direction: -1 | 1) {
    const index = columns.findIndex((current) => current.id === column.id);
    const targetIndex = index + direction;
    if (index < 0 || targetIndex < 0 || targetIndex >= columns.length) {
      return;
    }

    const reordered = [...columns];
    [reordered[index], reordered[targetIndex]] = [reordered[targetIndex], reordered[index]];
    setColumns(reordered);

    try {
      await reorderColumns(reordered.map((current) => current.id));
    } catch {
      appToast.error("Não foi possível reordenar as colunas.");
      loadBoard();
    }
  }

  const columnsForSheet = useMemo<TaskColumn[]>(() => columns, [columns]);

  if (loading) {
    return <DashboardPageLoader />;
  }

  if (!project) {
    return null;
  }

  return (
    <div className="space-y-5">
      <div className="flex flex-col gap-4 md:flex-row md:items-start md:justify-between">
        <div className="min-w-0 space-y-1.5">
          <Link
            href="/dashboard/tarefas"
            className="inline-flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground"
          >
            <ArrowLeft className="size-4" />
            Voltar para visão geral
          </Link>
          <div className="flex items-center gap-2">
            <span
              className="size-4 shrink-0 rounded-full"
              style={{ backgroundColor: project.color }}
            />
            <h1 className="truncate text-2xl font-semibold tracking-tight">
              {project.name}
            </h1>
          </div>
          {project.description ? (
            <p className="max-w-3xl text-sm text-muted-foreground">{project.description}</p>
          ) : null}
        </div>

        <div className="flex shrink-0 gap-2">
          <Button variant="outline" onClick={() => setProjectSheetOpen(true)}>
            <Pencil className="size-4" />
            Editar projeto
          </Button>
          <Button onClick={() => openCreateTask(columns[0]?.id ?? 0)} disabled={columns.length === 0}>
            <Plus className="size-4" />
            Nova tarefa
          </Button>
        </div>
      </div>

      {gamification ? <GamificationPanel summary={gamification} /> : null}

      <KanbanBoard
        columns={columns}
        setColumns={setColumns}
        onMovePersist={handleMovePersist}
        onAddTask={openCreateTask}
        onEditTask={openEditTask}
        onAddColumn={openCreateColumn}
        onEditColumn={openEditColumn}
        onDeleteColumn={handleColumnDelete}
        onMoveColumn={handleColumnMove}
      />

      <TaskSheet
        open={taskSheetOpen}
        onOpenChange={setTaskSheetOpen}
        projectId={projectId}
        columns={columnsForSheet}
        task={editingTask}
        defaultColumnId={defaultColumnId}
        onCreated={handleTaskCreated}
        onUpdated={handleTaskUpdated}
        onDeleted={handleTaskDeleted}
        onTimeChanged={updateTaskTracked}
      />

      <ColumnSheet
        open={columnSheetOpen}
        onOpenChange={setColumnSheetOpen}
        projectId={projectId}
        column={editingColumn}
        onCreated={handleColumnCreated}
        onUpdated={handleColumnUpdated}
      />

      <ProjectSheet
        open={projectSheetOpen}
        onOpenChange={setProjectSheetOpen}
        project={project}
        onUpdated={(updated) => setProject((prev) => ({ ...prev, ...updated }))}
      />
    </div>
  );
}

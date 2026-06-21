"use client";

import { useCallback, useEffect, useState } from "react";
import { useRouter } from "next/navigation";
import {
  AlertTriangle,
  CheckCircle2,
  FolderKanban,
  ListTodo,
  MoreVertical,
  Pencil,
  Plus,
  Sparkles,
  Trash2,
} from "lucide-react";

import { DashboardPageHeader } from "@/components/dashboard/page-header";
import { DashboardPageLoader } from "@/components/dashboard/page-loader";
import { SummaryCard } from "@/components/dashboard/summary-card";
import { GamificationPanel } from "@/components/tarefas/gamification-panel";
import { ProjectSheet } from "@/components/tarefas/project-sheet";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from "@/components/ui/dropdown-menu";
import { appToast } from "@/lib/toast";
import { deleteProject, getGamification, getProjects, getTasksOverview } from "@/services/api";
import { ApiError } from "@/services/apiError";
import type { GamificationSummary } from "@/types/Gamification";
import type { Project, TasksOverview } from "@/types/Task";

export default function TarefasOverviewPage() {
  const router = useRouter();
  const [projects, setProjects] = useState<Project[]>([]);
  const [overview, setOverview] = useState<TasksOverview | null>(null);
  const [gamification, setGamification] = useState<GamificationSummary | null>(null);
  const [loading, setLoading] = useState(true);

  const [projectSheetOpen, setProjectSheetOpen] = useState(false);
  const [editingProject, setEditingProject] = useState<Project | null>(null);

  const load = useCallback(async () => {
    try {
      const [projectList, overviewData, gami] = await Promise.all([
        getProjects(),
        getTasksOverview(),
        getGamification(),
      ]);
      setProjects(projectList);
      setOverview(overviewData);
      setGamification(gami);
    } catch (error) {
      appToast.error(
        error instanceof ApiError ? error.message : "Não foi possível carregar as tarefas.",
      );
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    load();
  }, [load]);

  function openCreateProject() {
    setEditingProject(null);
    setProjectSheetOpen(true);
  }

  function openEditProject(project: Project) {
    setEditingProject(project);
    setProjectSheetOpen(true);
  }

  async function handleDeleteProject(project: Project) {
    if (!window.confirm(`Excluir o projeto "${project.name}" e todas as suas tarefas?`)) {
      return;
    }

    try {
      await deleteProject(project.id);
      setProjects((prev) => prev.filter((current) => current.id !== project.id));
      appToast.success("Projeto removido.");
      void load();
    } catch (error) {
      appToast.error(
        error instanceof ApiError ? error.message : "Não foi possível remover o projeto.",
      );
    }
  }

  if (loading) {
    return <DashboardPageLoader />;
  }

  return (
    <div className="space-y-5">
      <DashboardPageHeader
        title="Tarefas"
        description="Organize seus projetos em quadros Kanban, arraste tarefas e ganhe XP ao concluí-las no prazo."
        actions={
          <Button onClick={openCreateProject}>
            <Plus className="size-4" />
            Novo projeto
          </Button>
        }
      />

      {gamification ? <GamificationPanel summary={gamification} /> : null}

      {overview ? (
        <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
          <SummaryCard
            label="Tarefas em aberto"
            value={overview.pending_tasks.toString()}
            icon={<ListTodo className="size-4" />}
          />
          <SummaryCard
            label="Concluídas"
            value={overview.completed_tasks.toString()}
            accentClass="text-emerald-600"
            icon={<CheckCircle2 className="size-4" />}
          />
          <SummaryCard
            label="Atrasadas"
            value={overview.overdue_tasks.toString()}
            accentClass={overview.overdue_tasks > 0 ? "text-red-600" : undefined}
            icon={<AlertTriangle className="size-4" />}
          />
          <SummaryCard
            label="Projetos"
            value={overview.projects.toString()}
            icon={<FolderKanban className="size-4" />}
          />
        </div>
      ) : null}

      <div>
        <h2 className="mb-3 text-sm font-semibold text-muted-foreground">Seus projetos</h2>

        {projects.length === 0 ? (
          <Card>
            <CardContent className="flex flex-col items-center gap-3 py-12 text-center">
              <FolderKanban className="size-8 text-muted-foreground" />
              <div>
                <p className="font-medium">Nenhum projeto ainda</p>
                <p className="text-sm text-muted-foreground">
                  Crie seu primeiro projeto para começar a organizar tarefas.
                </p>
              </div>
              <Button onClick={openCreateProject}>
                <Plus className="size-4" />
                Criar projeto
              </Button>
            </CardContent>
          </Card>
        ) : (
          <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
            {projects.map((project) => (
              <ProjectCard
                key={project.id}
                project={project}
                onOpen={() => router.push(`/dashboard/tarefas/${project.id}`)}
                onEdit={() => openEditProject(project)}
                onDelete={() => handleDeleteProject(project)}
              />
            ))}
          </div>
        )}
      </div>

      <ProjectSheet
        open={projectSheetOpen}
        onOpenChange={setProjectSheetOpen}
        project={editingProject}
        onCreated={(created) => router.push(`/dashboard/tarefas/${created.id}`)}
        onUpdated={(updated) =>
          setProjects((prev) =>
            prev.map((current) => (current.id === updated.id ? { ...current, ...updated } : current)),
          )
        }
      />
    </div>
  );
}

function ProjectCard({
  project,
  onOpen,
  onEdit,
  onDelete,
}: {
  project: Project;
  onOpen: () => void;
  onEdit: () => void;
  onDelete: () => void;
}) {
  const total = project.tasks_count ?? 0;
  const completed = project.completed_count ?? 0;
  const overdue = project.overdue_count ?? 0;
  const xp = project.xp ?? 0;
  const progress = total > 0 ? Math.round((completed / total) * 100) : 0;

  return (
    <Card
      className="group cursor-pointer transition hover:border-indigo-300 hover:shadow-md"
      onClick={onOpen}
    >
      <CardContent className="space-y-3 p-4">
        <div className="flex items-start justify-between gap-2">
          <div className="flex min-w-0 items-center gap-2">
            <span
              className="size-3 shrink-0 rounded-full"
              style={{ backgroundColor: project.color }}
            />
            <h3 className="truncate font-semibold">{project.name}</h3>
          </div>

          <div onClick={(event) => event.stopPropagation()}>
            <DropdownMenu>
              <DropdownMenuTrigger asChild>
                <Button variant="ghost" size="icon-sm" aria-label="Opções do projeto">
                  <MoreVertical className="size-4" />
                </Button>
              </DropdownMenuTrigger>
              <DropdownMenuContent align="end">
                <DropdownMenuItem onClick={onEdit}>
                  <Pencil className="size-4" />
                  Editar
                </DropdownMenuItem>
                <DropdownMenuSeparator />
                <DropdownMenuItem variant="destructive" onClick={onDelete}>
                  <Trash2 className="size-4" />
                  Excluir
                </DropdownMenuItem>
              </DropdownMenuContent>
            </DropdownMenu>
          </div>
        </div>

        {project.description ? (
          <p className="line-clamp-2 text-sm text-muted-foreground">{project.description}</p>
        ) : null}

        <div className="h-1.5 w-full overflow-hidden rounded-full bg-muted">
          <div
            className="h-full rounded-full bg-emerald-500 transition-all"
            style={{ width: `${progress}%` }}
          />
        </div>

        <div className="flex items-center justify-between text-xs text-muted-foreground">
          <span>
            {completed}/{total} concluídas
          </span>
          <div className="flex items-center gap-3">
            {overdue > 0 ? (
              <span className="font-medium text-red-600">{overdue} atrasada(s)</span>
            ) : null}
            <span className="inline-flex items-center gap-1 font-medium text-indigo-600">
              <Sparkles className="size-3" />
              {xp} XP
            </span>
          </div>
        </div>
      </CardContent>
    </Card>
  );
}

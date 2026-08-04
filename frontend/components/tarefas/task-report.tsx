"use client";

import { Fragment, useEffect, useMemo, useState } from "react";
import {
  AlertTriangle,
  CalendarClock,
  CheckCircle2,
  ChevronDown,
  ChevronRight,
  ListTodo,
  RotateCcw,
  Search,
  Trash2,
} from "lucide-react";

import { DashboardPageLoader } from "@/components/dashboard/page-loader";
import { SummaryCard } from "@/components/dashboard/summary-card";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@/components/ui/table";
import { formatDuration, todayISO } from "@/lib/format";
import {
  PRIORITY_META,
  PRIORITY_OPTIONS,
  formatDueDate,
  isOverdue,
} from "@/lib/tarefas";
import { appToast } from "@/lib/toast";
import {
  bulkDeleteTasks,
  getProjects,
  getTaskColumns,
  getTasks,
  moveTask,
  updateTask,
} from "@/services/api";
import { ApiError } from "@/services/apiError";
import type { Project, Task, TaskColumn, TaskPriority } from "@/types/Task";

import { fireConfetti } from "./confetti";
import { TaskSheet } from "./task-sheet";

type StatusFilter = "pending" | "completed" | "overdue" | "all";
type Preset = "today" | "overdue" | "week" | "no_due";
type GroupBy = "none" | "project" | "priority" | "stage";

const PRESET_LABELS: Record<Preset, string> = {
  today: "Hoje",
  overdue: "Atrasadas",
  week: "Esta semana",
  no_due: "Sem prazo",
};

const PRESET_ORDER: Preset[] = ["today", "overdue", "week", "no_due"];

function normalizeStage(name: string) {
  return name.trim().toLowerCase();
}

function toLocalISO(date: Date) {
  return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, "0")}-${String(date.getDate()).padStart(2, "0")}`;
}

/** Segunda a domingo da semana corrente, em "YYYY-MM-DD" local. */
function currentWeekRange() {
  const now = new Date();
  now.setHours(0, 0, 0, 0);
  const monday = new Date(now);
  monday.setDate(now.getDate() - ((now.getDay() + 6) % 7));
  const sunday = new Date(monday);
  sunday.setDate(monday.getDate() + 6);
  return { start: toLocalISO(monday), end: toLocalISO(sunday) };
}

export function TaskReport() {
  const [tasks, setTasks] = useState<Task[]>([]);
  const [projects, setProjects] = useState<Project[]>([]);
  const [allColumns, setAllColumns] = useState<TaskColumn[]>([]);
  const [loading, setLoading] = useState(true);

  const [search, setSearch] = useState("");
  const [projectFilter, setProjectFilter] = useState("all");
  const [priorityFilter, setPriorityFilter] = useState("all");
  const [statusFilter, setStatusFilter] = useState<StatusFilter>("pending");
  const [stageFilter, setStageFilter] = useState("all");
  const [preset, setPreset] = useState<Preset | null>(null);
  const [groupBy, setGroupBy] = useState<GroupBy>("none");

  const [selected, setSelected] = useState<Set<number>>(new Set());
  const [collapsedGroups, setCollapsedGroups] = useState<Set<string>>(new Set());
  const [bulkDeleting, setBulkDeleting] = useState(false);
  const [bulkMoving, setBulkMoving] = useState(false);
  const [sheetOpen, setSheetOpen] = useState(false);
  const [editingTaskId, setEditingTaskId] = useState<number | null>(null);

  useEffect(() => {
    let mounted = true;
    (async () => {
      try {
        const [tasksData, projectsData, columnsData] = await Promise.all([
          getTasks(),
          getProjects(),
          getTaskColumns(),
        ]);
        if (mounted) {
          setTasks(tasksData);
          setProjects(projectsData);
          setAllColumns(columnsData);
        }
      } catch (error) {
        appToast.error(
          error instanceof ApiError ? error.message : "Não foi possível carregar as tarefas.",
        );
      } finally {
        if (mounted) setLoading(false);
      }
    })();
    return () => {
      mounted = false;
    };
  }, []);

  // Derivado do estado, não um snapshot: mover a tarefa troca projeto e coluna,
  // e o sheet precisa enxergar isso.
  const editingTask = useMemo(
    () => tasks.find((task) => task.id === editingTaskId) ?? null,
    [tasks, editingTaskId],
  );

  const columnsByProject = useMemo(() => {
    const map = new Map<number, TaskColumn[]>();
    for (const column of allColumns) {
      const list = map.get(column.project_id) ?? [];
      list.push(column);
      map.set(column.project_id, list);
    }
    return map;
  }, [allColumns]);

  const stageOptions = useMemo(() => {
    const source =
      projectFilter !== "all"
        ? (columnsByProject.get(Number(projectFilter)) ?? [])
        : allColumns;
    const seen = new Map<string, string>();
    for (const column of source) {
      const key = normalizeStage(column.name);
      if (!seen.has(key)) seen.set(key, column.name.trim());
    }
    return Array.from(seen.entries()).map(([value, label]) => ({ value, label }));
  }, [allColumns, columnsByProject, projectFilter]);

  // Tudo aplicado, exceto o filtro de status — base dos KPIs (senão
  // "Pendentes" zeraria ao filtrar por concluídas, e vice-versa).
  const filteredBase = useMemo(() => {
    const query = search.trim().toLowerCase();
    const today = todayISO();
    const week = currentWeekRange();

    return tasks.filter((task) => {
      if (projectFilter !== "all" && task.project_id !== Number(projectFilter)) {
        return false;
      }
      if (priorityFilter !== "all" && task.priority !== priorityFilter) {
        return false;
      }
      if (
        stageFilter !== "all" &&
        normalizeStage(task.column?.name ?? "") !== stageFilter
      ) {
        return false;
      }
      if (
        query &&
        !task.title.toLowerCase().includes(query) &&
        !(task.description ?? "").toLowerCase().includes(query)
      ) {
        return false;
      }

      if (preset) {
        const due = task.due_date ? task.due_date.slice(0, 10) : null;
        const pending = !task.completed_at;
        if (preset === "today" && !(pending && due && due <= today)) return false;
        if (preset === "overdue" && !isOverdue(task.due_date, task.completed_at)) return false;
        if (preset === "week" && !(pending && due && due <= week.end)) return false;
        if (preset === "no_due" && !(pending && !due)) return false;
      }

      return true;
    });
  }, [tasks, search, projectFilter, priorityFilter, stageFilter, preset]);

  const filtered = useMemo(() => {
    if (statusFilter === "all") return filteredBase;
    return filteredBase.filter((task) => {
      if (statusFilter === "pending") return !task.completed_at;
      if (statusFilter === "completed") return Boolean(task.completed_at);
      return isOverdue(task.due_date, task.completed_at);
    });
  }, [filteredBase, statusFilter]);

  const kpis = useMemo(() => {
    const today = todayISO();
    const week = currentWeekRange();
    let pending = 0;
    let overdue = 0;
    let dueToday = 0;
    let completedThisWeek = 0;

    for (const task of filteredBase) {
      const due = task.due_date ? task.due_date.slice(0, 10) : null;
      if (!task.completed_at) {
        pending += 1;
        if (due === today) dueToday += 1;
      }
      if (isOverdue(task.due_date, task.completed_at)) overdue += 1;
      if (task.completed_at) {
        const completedDay = task.completed_at.slice(0, 10);
        if (completedDay >= week.start && completedDay <= week.end) {
          completedThisWeek += 1;
        }
      }
    }

    return { pending, overdue, dueToday, completedThisWeek };
  }, [filteredBase]);

  const groups = useMemo(() => {
    if (groupBy === "none") {
      return [{ key: "all", label: "", color: null as string | null, tasks: filtered }];
    }

    if (groupBy === "priority") {
      return PRIORITY_OPTIONS.map((priority) => ({
        key: `priority:${priority}`,
        label: PRIORITY_META[priority].label,
        color: null as string | null,
        tasks: filtered.filter((task) => task.priority === priority),
      })).filter((group) => group.tasks.length > 0);
    }

    if (groupBy === "project") {
      const byProject = new Map<number, Task[]>();
      for (const task of filtered) {
        const list = byProject.get(task.project_id) ?? [];
        list.push(task);
        byProject.set(task.project_id, list);
      }
      return Array.from(byProject.entries())
        .map(([projectId, groupTasks]) => ({
          key: `project:${projectId}`,
          label: groupTasks[0].project?.name ?? "Sem projeto",
          color: groupTasks[0].project?.color ?? null,
          tasks: groupTasks,
        }))
        .sort((a, b) => a.label.localeCompare(b.label, "pt-BR"));
    }

    // Etapa: agrupa por nome normalizado, ordenando pela menor posição da coluna.
    const byStage = new Map<string, { label: string; position: number; tasks: Task[] }>();
    const stagePosition = new Map<string, number>();
    for (const column of allColumns) {
      const key = normalizeStage(column.name);
      const current = stagePosition.get(key);
      if (current === undefined || column.position < current) {
        stagePosition.set(key, column.position);
      }
    }
    for (const task of filtered) {
      const key = normalizeStage(task.column?.name ?? "");
      const group = byStage.get(key) ?? {
        label: task.column?.name?.trim() || "Sem etapa",
        position: stagePosition.get(key) ?? 999,
        tasks: [],
      };
      group.tasks.push(task);
      byStage.set(key, group);
    }
    return Array.from(byStage.entries())
      .sort((a, b) => a[1].position - b[1].position)
      .map(([key, group]) => ({
        key: `stage:${key}`,
        label: group.label,
        color: null as string | null,
        tasks: group.tasks,
      }));
  }, [filtered, groupBy, allColumns]);

  const visibleIds = useMemo(() => filtered.map((task) => task.id), [filtered]);
  const allVisibleSelected =
    visibleIds.length > 0 && visibleIds.every((id) => selected.has(id));

  function patchTask(updated: Task) {
    setTasks((prev) =>
      prev.map((task) => (task.id === updated.id ? { ...task, ...updated } : task)),
    );
  }

  function removeTask(taskId: number) {
    setTasks((prev) => prev.filter((task) => task.id !== taskId));
    setSelected((prev) => {
      if (!prev.has(taskId)) return prev;
      const next = new Set(prev);
      next.delete(taskId);
      return next;
    });
  }

  function toggleSelected(taskId: number) {
    setSelected((prev) => {
      const next = new Set(prev);
      if (next.has(taskId)) {
        next.delete(taskId);
      } else {
        next.add(taskId);
      }
      return next;
    });
  }

  function toggleSelectAllVisible() {
    setSelected((prev) => {
      const next = new Set(prev);
      if (allVisibleSelected) {
        visibleIds.forEach((id) => next.delete(id));
      } else {
        visibleIds.forEach((id) => next.add(id));
      }
      return next;
    });
  }

  function toggleGroup(key: string) {
    setCollapsedGroups((prev) => {
      const next = new Set(prev);
      if (next.has(key)) {
        next.delete(key);
      } else {
        next.add(key);
      }
      return next;
    });
  }

  function togglePreset(value: Preset) {
    const next = preset === value ? null : value;
    setPreset(next);
    // Presets são recortes de pendências: sair de "Concluídas" evita lista vazia.
    if (next && statusFilter === "completed") {
      setStatusFilter("pending");
    }
  }

  async function handlePriorityChange(task: Task, priority: TaskPriority) {
    if (priority === task.priority) return;
    try {
      const updated = await updateTask(task.id, {
        title: task.title,
        description: task.description ?? undefined,
        priority,
        due_date: task.due_date ? task.due_date.slice(0, 10) : null,
      });
      patchTask(updated);
    } catch (error) {
      appToast.error(
        error instanceof ApiError ? error.message : "Não foi possível atualizar a prioridade.",
      );
    }
  }

  async function handleMove(task: Task, columnId: number) {
    if (columnId === task.task_column_id) return;
    try {
      const position = tasks.filter(
        (item) => item.task_column_id === columnId && item.id !== task.id,
      ).length;
      const result = await moveTask(task.id, { task_column_id: columnId, position });
      if (result.xp_gained > 0) {
        fireConfetti();
        appToast.success(`Tarefa concluída! +${result.xp_gained} XP 🎉`);
      }
      patchTask(result.task);
    } catch (error) {
      appToast.error(
        error instanceof ApiError ? error.message : "Não foi possível mover a tarefa.",
      );
    }
  }

  /** Reatribui a tarefa a outro projeto; o backend escolhe a coluna equivalente. */
  async function handleProjectChange(task: Task, projectId: number) {
    if (projectId === task.project_id) return;
    try {
      const result = await moveTask(task.id, { project_id: projectId });
      patchTask(result.task);
      appToast.success(
        `Movida para "${result.task.project?.name}" · etapa "${result.task.column?.name}".`,
      );
      if (result.xp_gained < 0) {
        appToast.error(
          `O destino não tem coluna de conclusão — ${-result.xp_gained} XP estornado.`,
        );
      }
    } catch (error) {
      appToast.error(
        error instanceof ApiError ? error.message : "Não foi possível mover a tarefa.",
      );
    }
  }

  // Sequencial de propósito: chamadas concorrentes calculariam a mesma posição
  // de append na coluna de destino e colidiriam.
  async function handleBulkMove(projectId: number) {
    const ids = [...selected];
    setBulkMoving(true);

    let moved = 0;
    const failed: string[] = [];

    for (const id of ids) {
      const task = tasks.find((item) => item.id === id);
      if (!task || task.project_id === projectId) continue;
      try {
        const result = await moveTask(id, { project_id: projectId });
        patchTask(result.task);
        moved += 1;
      } catch {
        failed.push(task.title);
      }
    }

    setBulkMoving(false);
    setSelected(new Set());

    if (moved > 0) {
      appToast.success(`${moved} tarefa(s) movida(s).`);
    }
    if (failed.length > 0) {
      appToast.error(
        `Falha em: ${failed.slice(0, 3).join(", ")}${failed.length > 3 ? "…" : ""}`,
      );
    }
  }

  function handleComplete(task: Task) {
    const done = columnsByProject
      .get(task.project_id)
      ?.find((column) => column.is_done_column);
    if (!done) {
      appToast.error("Este projeto não tem coluna de conclusão.");
      return;
    }
    void handleMove(task, done.id);
  }

  function handleReopen(task: Task) {
    const target = columnsByProject
      .get(task.project_id)
      ?.find((column) => !column.is_done_column);
    if (!target) {
      appToast.error("Este projeto não tem coluna pendente para reabrir.");
      return;
    }
    void handleMove(task, target.id);
  }

  async function handleBulkDelete() {
    if (selected.size === 0) return;
    if (
      !window.confirm(
        `Excluir ${selected.size} tarefa(s)? Essa ação não pode ser desfeita.`,
      )
    ) {
      return;
    }

    setBulkDeleting(true);
    try {
      const result = await bulkDeleteTasks([...selected]);
      setTasks((prev) => prev.filter((task) => !selected.has(task.id)));
      setSelected(new Set());
      appToast.success(result.message);
    } catch (error) {
      appToast.error(
        error instanceof ApiError ? error.message : "Não foi possível excluir as tarefas.",
      );
    } finally {
      setBulkDeleting(false);
    }
  }

  function openTask(task: Task) {
    setEditingTaskId(task.id);
    setSheetOpen(true);
  }

  if (loading) {
    return <DashboardPageLoader />;
  }

  const today = todayISO();

  return (
    <div className="space-y-5">
      <div className="no-print flex flex-wrap items-center gap-2">
        {PRESET_ORDER.map((value) => (
          <Button
            key={value}
            size="sm"
            variant={preset === value ? "default" : "outline"}
            onClick={() => togglePreset(value)}
          >
            {PRESET_LABELS[value]}
          </Button>
        ))}
      </div>

      <div className="no-print flex flex-wrap items-center gap-2">
        <div className="relative">
          <Search className="pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-muted-foreground" />
          <Input
            value={search}
            onChange={(event) => setSearch(event.target.value)}
            placeholder="Buscar tarefa..."
            className="w-56 pl-8"
          />
        </div>

        <Select value={projectFilter} onValueChange={setProjectFilter}>
          <SelectTrigger size="sm">
            <SelectValue />
          </SelectTrigger>
          <SelectContent>
            <SelectItem value="all">Todos os projetos</SelectItem>
            {projects.map((project) => (
              <SelectItem key={project.id} value={String(project.id)}>
                <span
                  className="size-2.5 shrink-0 rounded-full"
                  style={{ backgroundColor: project.color }}
                />
                {project.name}
              </SelectItem>
            ))}
          </SelectContent>
        </Select>

        <Select value={priorityFilter} onValueChange={setPriorityFilter}>
          <SelectTrigger size="sm">
            <SelectValue />
          </SelectTrigger>
          <SelectContent>
            <SelectItem value="all">Todas as prioridades</SelectItem>
            {PRIORITY_OPTIONS.map((priority) => (
              <SelectItem key={priority} value={priority}>
                <span
                  className={`size-2.5 shrink-0 rounded-full ${PRIORITY_META[priority].dot}`}
                />
                {PRIORITY_META[priority].label}
              </SelectItem>
            ))}
          </SelectContent>
        </Select>

        <Select
          value={statusFilter}
          onValueChange={(value) => {
            setStatusFilter(value as StatusFilter);
            if (value === "completed") setPreset(null);
          }}
        >
          <SelectTrigger size="sm">
            <SelectValue />
          </SelectTrigger>
          <SelectContent>
            <SelectItem value="pending">Pendentes</SelectItem>
            <SelectItem value="overdue">Atrasadas</SelectItem>
            <SelectItem value="completed">Concluídas</SelectItem>
            <SelectItem value="all">Todas</SelectItem>
          </SelectContent>
        </Select>

        <Select value={stageFilter} onValueChange={setStageFilter}>
          <SelectTrigger size="sm">
            <SelectValue />
          </SelectTrigger>
          <SelectContent>
            <SelectItem value="all">Todas as etapas</SelectItem>
            {stageOptions.map((option) => (
              <SelectItem key={option.value} value={option.value}>
                {option.label}
              </SelectItem>
            ))}
          </SelectContent>
        </Select>

        <Select value={groupBy} onValueChange={(value) => setGroupBy(value as GroupBy)}>
          <SelectTrigger size="sm">
            <SelectValue />
          </SelectTrigger>
          <SelectContent>
            <SelectItem value="none">Sem agrupamento</SelectItem>
            <SelectItem value="project">Agrupar por projeto</SelectItem>
            <SelectItem value="priority">Agrupar por prioridade</SelectItem>
            <SelectItem value="stage">Agrupar por etapa</SelectItem>
          </SelectContent>
        </Select>
      </div>

      <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
        <SummaryCard
          label="Pendentes"
          value={kpis.pending.toString()}
          accentClass="text-indigo-600"
          icon={<ListTodo className="size-4" />}
        />
        <SummaryCard
          label="Atrasadas"
          value={kpis.overdue.toString()}
          accentClass="text-red-600"
          icon={<AlertTriangle className="size-4" />}
        />
        <SummaryCard
          label="Vencem hoje"
          value={kpis.dueToday.toString()}
          accentClass="text-amber-600"
          icon={<CalendarClock className="size-4" />}
        />
        <SummaryCard
          label="Concluídas na semana"
          value={kpis.completedThisWeek.toString()}
          accentClass="text-emerald-600"
          icon={<CheckCircle2 className="size-4" />}
        />
      </div>

      {selected.size > 0 ? (
        <div className="no-print flex items-center gap-3 rounded-lg border bg-muted/40 px-3 py-2">
          <span className="text-sm font-medium">
            {selected.size} selecionada(s)
          </span>
          <Select
            value=""
            onValueChange={(value) => void handleBulkMove(Number(value))}
            disabled={bulkMoving || bulkDeleting}
          >
            <SelectTrigger size="sm" className="w-56" aria-label="Mover para projeto">
              <SelectValue placeholder="Mover para projeto…" />
            </SelectTrigger>
            <SelectContent>
              {projects.map((project) => (
                <SelectItem key={project.id} value={String(project.id)}>
                  <span
                    className="size-2.5 shrink-0 rounded-full"
                    style={{ backgroundColor: project.color }}
                  />
                  {project.name}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
          <Button
            variant="destructive"
            size="sm"
            onClick={handleBulkDelete}
            disabled={bulkDeleting || bulkMoving}
          >
            <Trash2 className="size-4" />
            Excluir selecionadas
          </Button>
          <Button
            variant="ghost"
            size="sm"
            onClick={() => setSelected(new Set())}
            disabled={bulkDeleting || bulkMoving}
          >
            Limpar seleção
          </Button>
        </div>
      ) : null}

      <Card>
        <CardContent>
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead className="w-8">
                  <input
                    type="checkbox"
                    className="size-4 accent-indigo-600"
                    checked={allVisibleSelected}
                    onChange={toggleSelectAllVisible}
                    aria-label="Selecionar todas as tarefas visíveis"
                  />
                </TableHead>
                <TableHead>Tarefa</TableHead>
                <TableHead>Projeto</TableHead>
                <TableHead>Prioridade</TableHead>
                <TableHead>Etapa</TableHead>
                <TableHead>Prazo</TableHead>
                <TableHead className="text-right">Tempo</TableHead>
                <TableHead className="w-10" />
              </TableRow>
            </TableHeader>
            <TableBody>
              {filtered.length === 0 ? (
                <TableRow>
                  <TableCell
                    colSpan={8}
                    className="py-10 text-center text-sm text-muted-foreground"
                  >
                    Nenhuma tarefa encontrada com os filtros atuais.
                  </TableCell>
                </TableRow>
              ) : (
                groups.map((group) => {
                  const collapsed = collapsedGroups.has(group.key);
                  return (
                    <Fragment key={group.key}>
                      {groupBy !== "none" ? (
                        <TableRow
                          className="cursor-pointer bg-muted/50 hover:bg-muted/70"
                          onClick={() => toggleGroup(group.key)}
                        >
                          <TableCell colSpan={8} className="py-2">
                            <div className="flex items-center gap-2">
                              {collapsed ? (
                                <ChevronRight className="size-4 text-muted-foreground" />
                              ) : (
                                <ChevronDown className="size-4 text-muted-foreground" />
                              )}
                              {group.color ? (
                                <span
                                  className="size-2.5 shrink-0 rounded-full"
                                  style={{ backgroundColor: group.color }}
                                />
                              ) : null}
                              <span className="text-sm font-semibold">{group.label}</span>
                              <span className="rounded-full bg-muted px-2 py-0.5 text-xs text-muted-foreground">
                                {group.tasks.length}
                              </span>
                            </div>
                          </TableCell>
                        </TableRow>
                      ) : null}

                      {!collapsed
                        ? group.tasks.map((task) => {
                            const due = task.due_date ? task.due_date.slice(0, 10) : null;
                            const overdue = isOverdue(task.due_date, task.completed_at);
                            const dueToday = !task.completed_at && due === today;
                            const projectColumns =
                              columnsByProject.get(task.project_id) ?? [];

                            return (
                              <TableRow
                                key={task.id}
                                className="cursor-pointer"
                                onClick={() => openTask(task)}
                              >
                                <TableCell onClick={(event) => event.stopPropagation()}>
                                  <input
                                    type="checkbox"
                                    className="size-4 accent-indigo-600"
                                    checked={selected.has(task.id)}
                                    onChange={() => toggleSelected(task.id)}
                                    aria-label={`Selecionar "${task.title}"`}
                                  />
                                </TableCell>
                                <TableCell className="max-w-64">
                                  <span
                                    className={`block truncate font-medium ${
                                      task.completed_at
                                        ? "text-muted-foreground line-through"
                                        : ""
                                    }`}
                                  >
                                    {task.title}
                                  </span>
                                </TableCell>
                                <TableCell onClick={(event) => event.stopPropagation()}>
                                  <Select
                                    value={String(task.project_id)}
                                    onValueChange={(value) =>
                                      handleProjectChange(task, Number(value))
                                    }
                                  >
                                    <SelectTrigger
                                      size="sm"
                                      className="h-7 gap-1 border-none bg-transparent px-1.5 shadow-none"
                                      aria-label="Projeto"
                                    >
                                      <div className="flex items-center gap-2">
                                        <span
                                          className="size-2.5 shrink-0 rounded-full"
                                          style={{
                                            backgroundColor:
                                              task.project?.color ?? "#94a3b8",
                                          }}
                                        />
                                        <span className="max-w-32 truncate text-sm text-muted-foreground">
                                          {task.project?.name ?? "—"}
                                        </span>
                                      </div>
                                    </SelectTrigger>
                                    <SelectContent>
                                      {projects.map((project) => (
                                        <SelectItem
                                          key={project.id}
                                          value={String(project.id)}
                                        >
                                          <span
                                            className="size-2.5 shrink-0 rounded-full"
                                            style={{ backgroundColor: project.color }}
                                          />
                                          {project.name}
                                        </SelectItem>
                                      ))}
                                    </SelectContent>
                                  </Select>
                                </TableCell>
                                <TableCell onClick={(event) => event.stopPropagation()}>
                                  <Select
                                    value={task.priority}
                                    onValueChange={(value) =>
                                      handlePriorityChange(task, value as TaskPriority)
                                    }
                                  >
                                    <SelectTrigger
                                      size="sm"
                                      className="h-7 gap-1 border-none bg-transparent px-1.5 shadow-none"
                                      aria-label="Prioridade"
                                    >
                                      <span
                                        className={`inline-flex items-center rounded-full border px-2 py-0.5 text-xs font-medium ${PRIORITY_META[task.priority].badge}`}
                                      >
                                        {PRIORITY_META[task.priority].label}
                                      </span>
                                    </SelectTrigger>
                                    <SelectContent>
                                      {PRIORITY_OPTIONS.map((priority) => (
                                        <SelectItem key={priority} value={priority}>
                                          <span
                                            className={`size-2.5 shrink-0 rounded-full ${PRIORITY_META[priority].dot}`}
                                          />
                                          {PRIORITY_META[priority].label}
                                        </SelectItem>
                                      ))}
                                    </SelectContent>
                                  </Select>
                                </TableCell>
                                <TableCell onClick={(event) => event.stopPropagation()}>
                                  <Select
                                    value={String(task.task_column_id)}
                                    onValueChange={(value) =>
                                      handleMove(task, Number(value))
                                    }
                                  >
                                    <SelectTrigger
                                      size="sm"
                                      className="h-7 gap-1 border-none bg-transparent px-1.5 shadow-none text-sm"
                                      aria-label="Etapa"
                                    >
                                      <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                      {projectColumns.map((column) => (
                                        <SelectItem
                                          key={column.id}
                                          value={String(column.id)}
                                        >
                                          {column.name}
                                        </SelectItem>
                                      ))}
                                    </SelectContent>
                                  </Select>
                                </TableCell>
                                <TableCell>
                                  <span
                                    className={`text-sm ${
                                      overdue
                                        ? "font-medium text-red-600"
                                        : dueToday
                                          ? "font-medium text-amber-600"
                                          : "text-muted-foreground"
                                    }`}
                                  >
                                    {formatDueDate(task.due_date)}
                                  </span>
                                </TableCell>
                                <TableCell className="text-right text-sm text-muted-foreground tabular-nums">
                                  {task.tracked_seconds
                                    ? formatDuration(task.tracked_seconds)
                                    : "—"}
                                </TableCell>
                                <TableCell onClick={(event) => event.stopPropagation()}>
                                  {task.completed_at ? (
                                    <Button
                                      variant="ghost"
                                      size="icon"
                                      className="size-7 text-muted-foreground"
                                      onClick={() => handleReopen(task)}
                                      title="Reabrir tarefa"
                                    >
                                      <RotateCcw className="size-4" />
                                    </Button>
                                  ) : (
                                    <Button
                                      variant="ghost"
                                      size="icon"
                                      className="size-7 text-emerald-600 hover:text-emerald-700"
                                      onClick={() => handleComplete(task)}
                                      title="Concluir tarefa"
                                    >
                                      <CheckCircle2 className="size-4" />
                                    </Button>
                                  )}
                                </TableCell>
                              </TableRow>
                            );
                          })
                        : null}
                    </Fragment>
                  );
                })
              )}
            </TableBody>
          </Table>
        </CardContent>
      </Card>

      {editingTask ? (
        <TaskSheet
          open={sheetOpen}
          onOpenChange={setSheetOpen}
          projectId={editingTask.project_id}
          columns={columnsByProject.get(editingTask.project_id) ?? []}
          task={editingTask}
          onUpdated={patchTask}
          onDeleted={removeTask}
          onTimeChanged={(taskId, totalSeconds) =>
            setTasks((prev) =>
              prev.map((task) =>
                task.id === taskId ? { ...task, tracked_seconds: totalSeconds } : task,
              ),
            )
          }
        />
      ) : null}
    </div>
  );
}

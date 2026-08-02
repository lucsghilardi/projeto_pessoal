"use client";

import { useEffect, useMemo, useState } from "react";
import {
  Bar,
  BarChart,
  CartesianGrid,
  Cell,
  Legend,
  Pie,
  PieChart,
  ResponsiveContainer,
  Tooltip as RechartsTooltip,
  XAxis,
  YAxis,
} from "recharts";
import {
  CheckCircle2,
  ChevronLeft,
  ChevronRight,
  Clock,
  Download,
  FolderKanban,
  Gauge,
  Printer,
} from "lucide-react";

import { DashboardPageLoader } from "@/components/dashboard/page-loader";
import { SummaryCard } from "@/components/dashboard/summary-card";
import { Button } from "@/components/ui/button";
import {
  Card,
  CardContent,
  CardHeader,
  CardTitle,
} from "@/components/ui/card";
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@/components/ui/table";
import { downloadCsv } from "@/lib/csv";
import {
  currentMonth,
  formatDuration,
  formatFullDate,
  monthLabel,
  monthShort,
  secondsToHours,
  shiftMonth,
} from "@/lib/format";
import { appToast } from "@/lib/toast";
import { getTimeReport } from "@/services/api";
import { ApiError } from "@/services/apiError";
import type { TimeReport as TimeReportData } from "@/types/Time";

const hoursTooltip = (value: unknown) => formatDuration(Number(value) * 3600);

export function TimeReport() {
  const [month, setMonth] = useState(currentMonth());
  const [report, setReport] = useState<TimeReportData | null>(null);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    let mounted = true;
    (async () => {
      setLoading(true);
      try {
        const data = await getTimeReport(month);
        if (mounted) setReport(data);
      } catch (error) {
        appToast.error(
          error instanceof ApiError ? error.message : "Não foi possível carregar o relatório.",
        );
      } finally {
        if (mounted) setLoading(false);
      }
    })();
    return () => {
      mounted = false;
    };
  }, [month]);

  const projectPie = useMemo(
    () =>
      (report?.by_project ?? []).map((project) => ({
        name: project.name,
        value: secondsToHours(project.seconds),
        color: project.color,
      })),
    [report],
  );

  const dailyBars = useMemo(
    () =>
      (report?.daily ?? []).map((day) => ({
        day: day.date.slice(8, 10),
        horas: secondsToHours(day.seconds),
      })),
    [report],
  );

  const trendBars = useMemo(
    () =>
      (report?.monthly_trend ?? []).map((item) => ({
        month: monthShort(item.month),
        horas: secondsToHours(item.seconds),
      })),
    [report],
  );

  const completedByProject = useMemo(() => {
    const groups = new Map<
      string,
      { color: string; tasks: TimeReportData["completed_tasks"] }
    >();
    for (const task of report?.completed_tasks ?? []) {
      const key = task.project_name ?? "Sem projeto";
      const group = groups.get(key) ?? { color: task.project_color ?? "#94a3b8", tasks: [] };
      group.tasks.push(task);
      groups.set(key, group);
    }
    return Array.from(groups.entries());
  }, [report]);

  function exportCsv() {
    if (!report) return;
    downloadCsv(
      `tempo-${month}`,
      ["Projeto", "Horas (decimal)", "Tarefas concluídas", "% do tempo", "Média h/tarefa"],
      report.by_project.map((project) => [
        project.name,
        secondsToHours(project.seconds).toString().replace(".", ","),
        project.tasks_completed,
        `${project.share_percent}%`,
        secondsToHours(project.avg_seconds_per_task).toString().replace(".", ","),
      ]),
    );
  }

  if (loading) {
    return <DashboardPageLoader />;
  }

  if (!report) {
    return null;
  }

  const hasData = report.totals.total_seconds > 0 || report.totals.tasks_completed > 0;

  return (
    <div className="space-y-5">
      <div className="no-print flex items-center gap-2">
        <Button variant="outline" size="icon" onClick={() => setMonth(shiftMonth(month, -1))}>
          <ChevronLeft className="size-4" />
        </Button>
        <span className="min-w-44 text-center text-sm font-medium">{monthLabel(month)}</span>
        <Button variant="outline" size="icon" onClick={() => setMonth(shiftMonth(month, 1))}>
          <ChevronRight className="size-4" />
        </Button>

        <div className="ml-auto flex items-center gap-2">
          <Button variant="outline" size="sm" onClick={() => window.print()}>
            <Printer className="size-4" />
            Imprimir / PDF
          </Button>
          <Button variant="outline" size="sm" onClick={exportCsv}>
            <Download className="size-4" />
            CSV
          </Button>
        </div>
      </div>

      <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
        <SummaryCard
          label="Tempo no mês"
          value={formatDuration(report.totals.total_seconds)}
          accentClass="text-indigo-600"
          icon={<Clock className="size-4" />}
        />
        <SummaryCard
          label="Tarefas concluídas"
          value={report.totals.tasks_completed.toString()}
          accentClass="text-emerald-600"
          icon={<CheckCircle2 className="size-4" />}
        />
        <SummaryCard
          label="Projetos ativos"
          value={report.totals.active_projects.toString()}
          icon={<FolderKanban className="size-4" />}
        />
        <SummaryCard
          label="Média por tarefa"
          value={formatDuration(report.totals.avg_seconds_per_task)}
          icon={<Gauge className="size-4" />}
        />
      </div>

      {!hasData ? (
        <Card>
          <CardContent className="py-12 text-center text-sm text-muted-foreground">
            Nenhum tempo registrado em {monthLabel(month)}.
          </CardContent>
        </Card>
      ) : (
        <>
          <div className="grid gap-3 lg:grid-cols-2">
            <Card>
              <CardHeader>
                <CardTitle className="text-base">Horas por projeto</CardTitle>
              </CardHeader>
              <CardContent>
                <div className="h-64 w-full">
                  <ResponsiveContainer width="100%" height="100%">
                    <PieChart>
                      <Pie
                        data={projectPie}
                        dataKey="value"
                        nameKey="name"
                        innerRadius={50}
                        outerRadius={90}
                        paddingAngle={2}
                      >
                        {projectPie.map((entry, index) => (
                          <Cell key={index} fill={entry.color || "#94a3b8"} />
                        ))}
                      </Pie>
                      <RechartsTooltip formatter={hoursTooltip} />
                      <Legend />
                    </PieChart>
                  </ResponsiveContainer>
                </div>
              </CardContent>
            </Card>

            <Card>
              <CardHeader>
                <CardTitle className="text-base">Tempo por dia</CardTitle>
              </CardHeader>
              <CardContent>
                <div className="h-64 w-full">
                  <ResponsiveContainer width="100%" height="100%">
                    <BarChart data={dailyBars} margin={{ top: 8, right: 8, bottom: 0, left: 8 }}>
                      <CartesianGrid strokeDasharray="3 3" vertical={false} className="stroke-muted" />
                      <XAxis dataKey="day" tickLine={false} axisLine={false} fontSize={11} interval={2} />
                      <YAxis tickLine={false} axisLine={false} width={32} fontSize={11} />
                      <RechartsTooltip formatter={hoursTooltip} />
                      <Bar dataKey="horas" fill="#6366f1" radius={[3, 3, 0, 0]} />
                    </BarChart>
                  </ResponsiveContainer>
                </div>
              </CardContent>
            </Card>
          </div>

          <Card>
            <CardHeader>
              <CardTitle className="text-base">
                Custo de tempo por cliente
                <span className="ml-2 text-sm font-normal text-muted-foreground">
                  ordenado pelo que mais consome
                </span>
              </CardTitle>
            </CardHeader>
            <CardContent>
              <Table>
                <TableHeader>
                  <TableRow>
                    <TableHead>Projeto / Cliente</TableHead>
                    <TableHead className="text-right">Tempo</TableHead>
                    <TableHead className="text-right">% do mês</TableHead>
                    <TableHead className="text-right">Concluídas</TableHead>
                    <TableHead className="text-right">Média/tarefa</TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {report.by_project.map((project, index) => (
                    <TableRow key={project.id} className={index === 0 ? "bg-amber-50/60" : ""}>
                      <TableCell>
                        <div className="flex items-center gap-2">
                          <span
                            className="size-3 shrink-0 rounded-full"
                            style={{ backgroundColor: project.color }}
                          />
                          <span className="font-medium">{project.name}</span>
                        </div>
                      </TableCell>
                      <TableCell className="text-right tabular-nums">
                        {formatDuration(project.seconds)}
                      </TableCell>
                      <TableCell className="text-right tabular-nums">
                        {project.share_percent}%
                      </TableCell>
                      <TableCell className="text-right tabular-nums">
                        {project.tasks_completed}
                      </TableCell>
                      <TableCell className="text-right tabular-nums">
                        {project.tasks_completed > 0
                          ? formatDuration(project.avg_seconds_per_task)
                          : "—"}
                      </TableCell>
                    </TableRow>
                  ))}
                </TableBody>
              </Table>
            </CardContent>
          </Card>

          <Card>
            <CardHeader>
              <CardTitle className="text-base">Feito em {monthLabel(month)}</CardTitle>
            </CardHeader>
            <CardContent className="space-y-4">
              {completedByProject.length === 0 ? (
                <p className="py-2 text-center text-sm text-muted-foreground">
                  Nenhuma tarefa concluída neste mês.
                </p>
              ) : (
                completedByProject.map(([name, group]) => (
                  <div key={name}>
                    <div className="mb-1.5 flex items-center gap-2">
                      <span
                        className="size-2.5 rounded-full"
                        style={{ backgroundColor: group.color }}
                      />
                      <span className="text-sm font-semibold">{name}</span>
                      <span className="text-xs text-muted-foreground">
                        {group.tasks.length} tarefa(s)
                      </span>
                    </div>
                    <ul className="space-y-1 pl-4">
                      {group.tasks.map((task) => (
                        <li
                          key={task.id}
                          className="flex items-center justify-between gap-3 border-b py-1 text-sm last:border-0"
                        >
                          <span className="min-w-0 truncate">{task.title}</span>
                          <span className="flex shrink-0 items-center gap-3 text-xs text-muted-foreground">
                            {task.completed_at ? (
                              <span>{formatFullDate(task.completed_at)}</span>
                            ) : null}
                            <span className="tabular-nums text-indigo-600">
                              {formatDuration(task.seconds)}
                            </span>
                          </span>
                        </li>
                      ))}
                    </ul>
                  </div>
                ))
              )}
            </CardContent>
          </Card>

          <Card>
            <CardHeader>
              <CardTitle className="text-base">Tendência (6 meses)</CardTitle>
            </CardHeader>
            <CardContent>
              <div className="h-56 w-full">
                <ResponsiveContainer width="100%" height="100%">
                  <BarChart data={trendBars} margin={{ top: 8, right: 8, bottom: 0, left: 8 }}>
                    <CartesianGrid strokeDasharray="3 3" vertical={false} className="stroke-muted" />
                    <XAxis dataKey="month" tickLine={false} axisLine={false} fontSize={12} />
                    <YAxis tickLine={false} axisLine={false} width={32} fontSize={11} />
                    <RechartsTooltip formatter={hoursTooltip} />
                    <Bar dataKey="horas" fill="#0ea5e9" radius={[4, 4, 0, 0]} />
                  </BarChart>
                </ResponsiveContainer>
              </div>
            </CardContent>
          </Card>
        </>
      )}
    </div>
  );
}

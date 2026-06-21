export interface TimeEntry {
  id: number;
  user_id: number;
  project_id: number;
  task_id: number | null;
  started_at: string;
  ended_at: string | null;
  duration_seconds: number;
  note?: string | null;
  created_at?: string;
  updated_at?: string;
  task?: { id: number; title: string; project_id: number } | null;
  project?: { id: number; name: string; color: string } | null;
}

export type ActiveTimer = TimeEntry | null;

export interface CreateTimeEntryPayload {
  task_id: number;
  started_at: string; // YYYY-MM-DD
  minutes: number;
  note?: string;
}

export interface UpdateTimeEntryPayload {
  started_at: string;
  minutes: number;
  note?: string;
}

export interface TimeReportTotals {
  total_seconds: number;
  tasks_completed: number;
  active_projects: number;
  avg_seconds_per_task: number;
}

export interface TimeReportProject {
  id: number;
  name: string;
  color: string;
  seconds: number;
  tasks_completed: number;
  share_percent: number;
  avg_seconds_per_task: number;
}

export interface TimeReportDay {
  date: string;
  seconds: number;
}

export interface TimeReportMonth {
  month: string;
  seconds: number;
}

export interface TimeReportCompletedTask {
  id: number;
  title: string;
  project_name: string | null;
  project_color: string | null;
  completed_at: string | null;
  seconds: number;
}

export interface TimeReport {
  month: string;
  totals: TimeReportTotals;
  by_project: TimeReportProject[];
  daily: TimeReportDay[];
  monthly_trend: TimeReportMonth[];
  completed_tasks: TimeReportCompletedTask[];
}

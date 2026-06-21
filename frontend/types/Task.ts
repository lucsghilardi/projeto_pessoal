export type TaskPriority = 'low' | 'medium' | 'high' | 'urgent';

export interface Task {
  id: number;
  user_id: number;
  project_id: number;
  task_column_id: number;
  title: string;
  description?: string | null;
  priority: TaskPriority;
  due_date?: string | null;
  position: number;
  completed_at?: string | null;
  xp_awarded: number;
  tracked_seconds?: number;
  created_at?: string;
  updated_at?: string;
  project?: Pick<Project, 'id' | 'name' | 'color'>;
  column?: Pick<TaskColumn, 'id' | 'name' | 'is_done_column'>;
}

export interface TaskColumn {
  id: number;
  project_id: number;
  name: string;
  position: number;
  is_done_column: boolean;
  color?: string | null;
  tasks?: Task[];
}

export interface Project {
  id: number;
  name: string;
  color: string;
  description?: string | null;
  archived_at?: string | null;
  created_at?: string;
  updated_at?: string;
  // Agregados retornados em /projects (index)
  tasks_count?: number;
  completed_count?: number;
  overdue_count?: number;
  xp?: number | null;
  tracked_seconds?: number | null;
}

export interface ProjectBoard extends Project {
  columns: TaskColumn[];
}

export interface CreateProjectPayload {
  name: string;
  color: string;
  description?: string;
}

export type UpdateProjectPayload = CreateProjectPayload;

export interface CreateColumnPayload {
  name: string;
  color?: string | null;
  is_done_column?: boolean;
}

export type UpdateColumnPayload = CreateColumnPayload;

export interface CreateTaskPayload {
  project_id: number;
  task_column_id: number;
  title: string;
  description?: string;
  priority: TaskPriority;
  due_date?: string | null;
}

export interface UpdateTaskPayload {
  title: string;
  description?: string;
  priority: TaskPriority;
  due_date?: string | null;
}

export interface MoveTaskPayload {
  task_column_id: number;
  position: number;
}

export interface TasksOverview {
  projects: number;
  total_tasks: number;
  completed_tasks: number;
  pending_tasks: number;
  overdue_tasks: number;
}

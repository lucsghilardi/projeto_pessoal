export interface Achievement {
  key: string;
  label: string;
  description: string;
  icon: string;
  unlocked: boolean;
  unlocked_at?: string | null;
}

export interface ProjectRankingEntry {
  id: number;
  name: string;
  color: string;
  xp: number;
  completed_tasks: number;
}

export interface GamificationSummary {
  total_xp: number;
  level: number;
  title: string;
  current_streak: number;
  longest_streak: number;
  last_completed_date?: string | null;
  xp_into_level: number;
  xp_for_next_level: number;
  next_level_total_xp: number;
  achievements: Achievement[];
  project_ranking: ProjectRankingEntry[];
}

export interface MoveTaskResult {
  task: import('./Task').Task;
  xp_gained: number;
  gamification: GamificationSummary;
}

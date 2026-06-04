export type UserRole = 'admin' | 'editor' | 'viewer';

export interface User {
  id: number;
  name: string;
  email: string;
  role: UserRole;
  is_active: boolean;
  created_at?: string;
  updated_at?: string;
}

export interface CreateUserPayload {
  name: string;
  email: string;
  role: UserRole;
  is_active?: boolean;
  password: string;
  password_confirmation: string;
}

export interface UpdateUserPayload {
  name: string;
  email: string;
  role: UserRole;
  is_active: boolean;
  password?: string;
  password_confirmation?: string;
}

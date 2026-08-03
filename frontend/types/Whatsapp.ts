import type { Task, TaskPriority } from '@/types/Task';

export type WhatsappInstanciaStatus = 'desconectado' | 'conectando' | 'conectado';

export interface WhatsappInstancia {
  id: number;
  user_id: number;
  apelido?: string | null;
  instance_name: string;
  phone?: string | null;
  status: WhatsappInstanciaStatus;
  gtd_ativo: boolean;
  relatorio_diario_ativo: boolean;
  resumo_matinal_ativo: boolean;
  created_at?: string;
  updated_at?: string;
}

export interface WhatsappInstanciaPayload {
  apelido?: string | null;
  gtd_ativo?: boolean;
  relatorio_diario_ativo?: boolean;
  resumo_matinal_ativo?: boolean;
}

export interface WhatsappQrcode {
  base64: string;
  code: string;
  pairing_code: string;
}

export interface WhatsappStatusResponse {
  instancia: WhatsappInstancia;
  connected: boolean;
  session: string;
  erro?: string;
}

export interface WhatsappChat {
  id: number;
  instancia_id: number;
  chave: string;
  phone?: string | null;
  chat_lid?: string | null;
  chat_name?: string | null;
  sender_name?: string | null;
  photo?: string | null;
  is_group: boolean;
  monitorado: boolean;
  monitorado_em?: string | null;
  arquivado: boolean;
  last_message_id?: string | null;
  last_message_text?: string | null;
  last_message_from_me: boolean;
  last_message_at?: string | null;
  last_inbound_at?: string | null;
  unread_count: number;
  created_at?: string;
  updated_at?: string;
}

export interface WhatsappMensagem {
  id: number;
  chat_id: number;
  message_id?: string | null;
  phone?: string | null;
  from_me: boolean;
  sender_name?: string | null;
  tipo: string;
  texto?: string | null;
  caption?: string | null;
  media_url?: string | null;
  media_mime?: string | null;
  media_filename?: string | null;
  status?: string | null;
  momment: number;
  quoted_message_id?: string | null;
  quoted_texto?: string | null;
  origem?: string | null;
  created_at?: string;
}

export interface WhatsappAtencaoItem {
  chat_id: number;
  nome: string;
  monitorado: boolean;
  is_group: boolean;
  ultima_mensagem?: string | null;
  desde?: string | null;
  horas?: number | null;
}

export interface WhatsappAtencao {
  aguardando_voce: WhatsappAtencaoItem[];
  aguardando_eles: WhatsappAtencaoItem[];
}

export type WhatsappSugestaoStatus = 'pendente' | 'aceita' | 'descartada';
export type WhatsappSugestaoOrigem = 'relatorio' | 'analise' | 'gtd';

export interface WhatsappSugestao {
  id: number;
  user_id: number;
  chat_id?: number | null;
  relatorio_id?: number | null;
  origem: WhatsappSugestaoOrigem;
  titulo: string;
  descricao?: string | null;
  prioridade: TaskPriority;
  due_date?: string | null;
  contexto?: string | null;
  status: WhatsappSugestaoStatus;
  task_id?: number | null;
  chat?: Pick<WhatsappChat, 'id' | 'chat_name' | 'sender_name' | 'phone' | 'chave'> | null;
  task?: Pick<Task, 'id' | 'title' | 'project_id'> | null;
  created_at?: string;
}

export interface WhatsappAceitarSugestaoPayload {
  project_id?: number;
  column_id?: number;
  titulo?: string;
  due_date?: string;
  prioridade?: TaskPriority;
}

export interface WhatsappRelatorioPendencia {
  chat_id?: number | null;
  contato: string;
  assunto: string;
  ultima_mensagem?: string;
  horas_esperando?: number | null;
  urgencia: 'alta' | 'media' | 'baixa';
}

export interface WhatsappRelatorioPromessa {
  chat_id?: number | null;
  contato: string;
  promessa: string;
  prazo_mencionado?: string | null;
}

export interface WhatsappRelatorioAssunto {
  chat_id?: number | null;
  contato: string;
  assunto: string;
}

export interface WhatsappRelatorioSugestaoTarefa {
  chat_id?: number | null;
  contato?: string | null;
  titulo: string;
  descricao?: string | null;
  prioridade: TaskPriority;
  due_date?: string | null;
}

export interface WhatsappRelatorioDados {
  resumo: string;
  pendencias: WhatsappRelatorioPendencia[];
  promessas: WhatsappRelatorioPromessa[];
  assuntos_perdidos: WhatsappRelatorioAssunto[];
  sugestoes_tarefas: WhatsappRelatorioSugestaoTarefa[];
}

export type WhatsappRelatorioTipo = 'diario' | 'matinal';

export interface WhatsappRelatorio {
  id: number;
  user_id: number;
  tipo: WhatsappRelatorioTipo;
  referencia_data: string;
  dados: WhatsappRelatorioDados;
  texto_whatsapp?: string | null;
  enviado_em?: string | null;
  sugestoes?: WhatsappSugestao[];
  created_at?: string;
}

export interface WhatsappAnaliseResultado {
  resumo: string;
  pontos_de_atencao: string[];
  sugestoes: WhatsappSugestao[];
}

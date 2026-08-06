// Helpers de formatação compartilhados pelas telas do dashboard.
//
// Datas: o backend devolve datas-calendário ("YYYY-MM-DD"). Nunca passe esses
// valores em `new Date(string)` para exibição — o JS interpreta como meia-noite
// UTC e, no fuso do Brasil, o dia exibido volta um dia. Aqui tudo é formatado
// a partir da string, e "hoje"/"mês atual" usam o relógio local.

export function formatCurrency(value: number, currency: string = "BRL") {
  return new Intl.NumberFormat(currency === "USD" ? "en-US" : "pt-BR", {
    style: "currency",
    currency: currency === "USD" ? "USD" : "BRL",
  }).format(value);
}

export function compactCurrency(value: number) {
  return new Intl.NumberFormat("pt-BR", { notation: "compact", maximumFractionDigits: 1 }).format(value);
}

/** 12.3 -> "+12,30%" / -4 -> "-4,00%" (rentabilidade). */
export function formatPercent(value: number) {
  return `${value > 0 ? "+" : ""}${value.toFixed(2).replace(".", ",")}%`;
}

export function toNumber(value: string | number) {
  return typeof value === "number" ? value : Number.parseFloat(value || "0");
}

/** Segundos -> "1h 30m" / "45m" / "2h" / "0m". */
export function formatDuration(seconds: number) {
  const total = Math.max(0, Math.round(seconds));
  const hours = Math.floor(total / 3600);
  const minutes = Math.floor((total % 3600) / 60);

  if (hours > 0 && minutes > 0) return `${hours}h ${minutes}m`;
  if (hours > 0) return `${hours}h`;
  return `${minutes}m`;
}

/** Segundos -> "00:12:34" (relógio do cronômetro). */
export function formatClock(seconds: number) {
  const total = Math.max(0, Math.floor(seconds));
  const h = String(Math.floor(total / 3600)).padStart(2, "0");
  const m = String(Math.floor((total % 3600) / 60)).padStart(2, "0");
  const s = String(total % 60).padStart(2, "0");
  return `${h}:${m}:${s}`;
}

/** Segundos -> horas decimais (para gráficos). */
export function secondsToHours(seconds: number) {
  return Math.round((seconds / 3600) * 100) / 100;
}

/** 92.5 -> "92,5 kg" (módulo Saúde). */
export function formatKg(value: number) {
  return `${value.toLocaleString("pt-BR", { minimumFractionDigits: 1, maximumFractionDigits: 1 })} kg`;
}

/** Data de hoje (fuso local) em "YYYY-MM-DD". */
export function todayISO() {
  const d = new Date();
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, "0")}-${String(d.getDate()).padStart(2, "0")}`;
}

/** Mês atual (fuso local) em "YYYY-MM". */
export function currentMonth() {
  return todayISO().slice(0, 7);
}

/** "2026-06" -> "Junho de 2026" */
export function monthLabel(month: string) {
  const [year, mo] = month.split("-").map(Number);
  return new Date(year, mo - 1, 1)
    .toLocaleDateString("pt-BR", { month: "long", year: "numeric" })
    .replace(/^./, (c) => c.toUpperCase());
}

/** "2026-06" -> "jun/26" */
export function monthShort(month: string) {
  const [year, mo] = month.split("-").map(Number);
  return new Date(year, mo - 1, 1)
    .toLocaleDateString("pt-BR", { month: "short", year: "2-digit" })
    .replace(".", "");
}

/** Soma `delta` meses a um "YYYY-MM". */
export function shiftMonth(month: string, delta: number) {
  const [year, mo] = month.split("-").map(Number);
  const d = new Date(year, mo - 1 + delta, 1);
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, "0")}`;
}

function datePart(value: string): [string, string, string] | null {
  const match = /^(\d{4})-(\d{2})-(\d{2})/.exec(value);
  return match ? [match[1], match[2], match[3]] : null;
}

/** "2026-06-09..." -> "09/06" (sem conversão de fuso). */
export function formatDate(value: string) {
  const parts = datePart(value);
  return parts ? `${parts[2]}/${parts[1]}` : value;
}

/** "2026-06-09..." -> "09/06/2026" (sem conversão de fuso). */
export function formatFullDate(value: string) {
  const parts = datePart(value);
  return parts ? `${parts[2]}/${parts[1]}/${parts[0]}` : value;
}

// Exportação de CSV no cliente, sem dependências.

function escapeCell(value: string | number): string {
  const text = String(value ?? "");
  if (/[";\n]/.test(text)) {
    return `"${text.replace(/"/g, '""')}"`;
  }
  return text;
}

/** Monta um CSV (separador ";", amigável ao Excel pt-BR) e dispara o download. */
export function downloadCsv(
  filename: string,
  headers: string[],
  rows: Array<Array<string | number>>,
) {
  const lines = [headers, ...rows]
    .map((row) => row.map(escapeCell).join(";"))
    .join("\r\n");

  // BOM para o Excel reconhecer UTF-8 (acentos).
  const blob = new Blob(["﻿" + lines], { type: "text/csv;charset=utf-8;" });
  const url = URL.createObjectURL(blob);
  const anchor = document.createElement("a");
  anchor.href = url;
  anchor.download = filename.endsWith(".csv") ? filename : `${filename}.csv`;
  document.body.appendChild(anchor);
  anchor.click();
  document.body.removeChild(anchor);
  URL.revokeObjectURL(url);
}

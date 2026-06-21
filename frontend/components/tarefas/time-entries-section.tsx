"use client";

import { useCallback, useEffect, useState } from "react";
import { Plus, Timer, Trash2 } from "lucide-react";

import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Spinner } from "@/components/ui/spinner";
import { formatDuration, formatFullDate, todayISO } from "@/lib/format";
import { appToast } from "@/lib/toast";
import {
  createTimeEntry,
  deleteTimeEntry,
  getTaskTimeEntries,
} from "@/services/api";
import { ApiError } from "@/services/apiError";
import type { TimeEntry } from "@/types/Time";

export function TimeEntriesSection({
  taskId,
  onTotalChange,
}: {
  taskId: number;
  onTotalChange?: (totalSeconds: number) => void;
}) {
  const [entries, setEntries] = useState<TimeEntry[]>([]);
  const [loading, setLoading] = useState(true);
  const [date, setDate] = useState(todayISO());
  const [hours, setHours] = useState("");
  const [minutes, setMinutes] = useState("");
  const [note, setNote] = useState("");
  const [saving, setSaving] = useState(false);

  const notifyTotal = useCallback(
    (list: TimeEntry[]) => {
      onTotalChange?.(list.reduce((sum, entry) => sum + entry.duration_seconds, 0));
    },
    [onTotalChange],
  );

  useEffect(() => {
    let mounted = true;
    setLoading(true);
    getTaskTimeEntries(taskId)
      .then((list) => {
        if (mounted) {
          setEntries(list);
        }
      })
      .catch(() => {
        if (mounted) appToast.error("Não foi possível carregar os tempos.");
      })
      .finally(() => {
        if (mounted) setLoading(false);
      });
    return () => {
      mounted = false;
    };
  }, [taskId]);

  const total = entries.reduce((sum, entry) => sum + entry.duration_seconds, 0);

  async function handleAdd(event: React.FormEvent) {
    event.preventDefault();
    const totalMinutes = (Number(hours) || 0) * 60 + (Number(minutes) || 0);

    if (totalMinutes <= 0) {
      appToast.error("Informe um tempo maior que zero.");
      return;
    }

    setSaving(true);
    try {
      const created = await createTimeEntry({
        task_id: taskId,
        started_at: date,
        minutes: totalMinutes,
        note: note.trim() || undefined,
      });
      const next = [created, ...entries];
      setEntries(next);
      notifyTotal(next);
      setHours("");
      setMinutes("");
      setNote("");
      appToast.success("Tempo registrado.");
    } catch (error) {
      appToast.error(
        error instanceof ApiError ? error.message : "Não foi possível registrar o tempo.",
      );
    } finally {
      setSaving(false);
    }
  }

  async function handleDelete(entry: TimeEntry) {
    try {
      await deleteTimeEntry(entry.id);
      const next = entries.filter((current) => current.id !== entry.id);
      setEntries(next);
      notifyTotal(next);
    } catch (error) {
      appToast.error(
        error instanceof ApiError ? error.message : "Não foi possível remover o tempo.",
      );
    }
  }

  return (
    <div className="space-y-3 border-t pt-4">
      <div className="flex items-center justify-between">
        <span className="text-sm font-semibold">Tempo registrado</span>
        <span className="inline-flex items-center gap-1 text-sm font-medium text-indigo-600">
          <Timer className="size-4" />
          {formatDuration(total)}
        </span>
      </div>

      <form onSubmit={handleAdd} className="flex flex-wrap items-end gap-2">
        <div className="flex flex-col gap-1">
          <label className="text-xs text-muted-foreground">Data</label>
          <Input
            type="date"
            value={date}
            onChange={(event) => setDate(event.target.value)}
            className="w-36"
          />
        </div>
        <div className="flex flex-col gap-1">
          <label className="text-xs text-muted-foreground">Horas</label>
          <Input
            type="number"
            min={0}
            value={hours}
            onChange={(event) => setHours(event.target.value)}
            placeholder="0"
            className="w-16"
          />
        </div>
        <div className="flex flex-col gap-1">
          <label className="text-xs text-muted-foreground">Min</label>
          <Input
            type="number"
            min={0}
            max={59}
            value={minutes}
            onChange={(event) => setMinutes(event.target.value)}
            placeholder="0"
            className="w-16"
          />
        </div>
        <Button type="submit" size="sm" variant="outline" disabled={saving}>
          {saving ? <Spinner /> : <Plus className="size-4" />}
          Adicionar
        </Button>
      </form>

      <Input
        value={note}
        onChange={(event) => setNote(event.target.value)}
        placeholder="Nota (opcional)"
      />

      {loading ? (
        <p className="py-2 text-center text-xs text-muted-foreground">Carregando…</p>
      ) : entries.length === 0 ? (
        <p className="py-2 text-center text-xs text-muted-foreground">
          Nenhum tempo registrado ainda.
        </p>
      ) : (
        <ul className="space-y-1.5">
          {entries.map((entry) => {
            const running = entry.ended_at === null;
            return (
              <li
                key={entry.id}
                className="flex items-center justify-between gap-2 rounded-md border bg-card px-2.5 py-1.5 text-sm"
              >
                <div className="flex min-w-0 flex-col">
                  <span className="font-medium tabular-nums">
                    {running ? "Em andamento" : formatDuration(entry.duration_seconds)}
                  </span>
                  <span className="truncate text-xs text-muted-foreground">
                    {formatFullDate(entry.started_at)}
                    {entry.note ? ` · ${entry.note}` : ""}
                  </span>
                </div>
                {!running ? (
                  <Button
                    type="button"
                    variant="ghost"
                    size="icon-sm"
                    className="text-muted-foreground hover:text-destructive"
                    onClick={() => handleDelete(entry)}
                    aria-label="Remover tempo"
                  >
                    <Trash2 className="size-4" />
                  </Button>
                ) : null}
              </li>
            );
          })}
        </ul>
      )}
    </div>
  );
}

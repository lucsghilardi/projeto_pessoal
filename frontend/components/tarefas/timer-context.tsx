"use client";

import {
  createContext,
  useCallback,
  useContext,
  useEffect,
  useRef,
  useState,
} from "react";

import { appToast } from "@/lib/toast";
import { getActiveTimer, startTimer, stopTimer } from "@/services/api";
import { ApiError } from "@/services/apiError";
import type { TimeEntry } from "@/types/Time";

type StoppedListener = (entry: TimeEntry) => void;

type TimerContextValue = {
  active: TimeEntry | null;
  elapsedSeconds: number;
  starting: boolean;
  stopping: boolean;
  isActive: (taskId: number) => boolean;
  start: (taskId: number) => Promise<void>;
  stop: () => Promise<void>;
  /** Notifica quando um cronômetro é parado (para atualizar o tempo acumulado). */
  subscribeStopped: (listener: StoppedListener) => () => void;
};

const TimerContext = createContext<TimerContextValue | null>(null);

/** Garante que só uma entrada válida (com id e início) vire cronômetro ativo. */
function normalizeEntry(entry: TimeEntry | null): TimeEntry | null {
  return entry && entry.id && entry.started_at ? entry : null;
}

function elapsedFrom(entry: TimeEntry | null): number {
  if (!entry) {
    return 0;
  }
  const started = new Date(entry.started_at).getTime();
  if (Number.isNaN(started)) {
    return 0;
  }
  return Math.max(0, Math.floor((Date.now() - started) / 1000));
}

export function TimerProvider({ children }: { children: React.ReactNode }) {
  const [active, setActive] = useState<TimeEntry | null>(null);
  const [elapsedSeconds, setElapsedSeconds] = useState(0);
  const [starting, setStarting] = useState(false);
  const [stopping, setStopping] = useState(false);
  const listeners = useRef<Set<StoppedListener>>(new Set());

  useEffect(() => {
    let mounted = true;
    getActiveTimer()
      .then((entry) => {
        if (mounted) {
          const valid = normalizeEntry(entry);
          setActive(valid);
          setElapsedSeconds(elapsedFrom(valid));
        }
      })
      .catch(() => {
        // sem cronômetro ativo / falha silenciosa
      });
    return () => {
      mounted = false;
    };
  }, []);

  useEffect(() => {
    if (!active) {
      setElapsedSeconds(0);
      return;
    }

    setElapsedSeconds(elapsedFrom(active));
    const interval = setInterval(() => setElapsedSeconds(elapsedFrom(active)), 1000);
    return () => clearInterval(interval);
  }, [active]);

  const start = useCallback(async (taskId: number) => {
    setStarting(true);
    try {
      const entry = normalizeEntry(await startTimer(taskId));
      setActive(entry);
      setElapsedSeconds(0);
    } catch (error) {
      appToast.error(
        error instanceof ApiError ? error.message : "Não foi possível iniciar o cronômetro.",
      );
    } finally {
      setStarting(false);
    }
  }, []);

  const stop = useCallback(async () => {
    setActive((current) => {
      if (current) {
        setStopping(true);
        stopTimer(current.id)
          .then((stopped) => {
            listeners.current.forEach((listener) => listener(stopped));
          })
          .catch((error) => {
            appToast.error(
              error instanceof ApiError ? error.message : "Não foi possível parar o cronômetro.",
            );
          })
          .finally(() => setStopping(false));
      }
      return null;
    });
  }, []);

  const isActive = useCallback(
    (taskId: number) => active?.task_id === taskId,
    [active],
  );

  const subscribeStopped = useCallback((listener: StoppedListener) => {
    listeners.current.add(listener);
    return () => {
      listeners.current.delete(listener);
    };
  }, []);

  return (
    <TimerContext.Provider
      value={{ active, elapsedSeconds, starting, stopping, isActive, start, stop, subscribeStopped }}
    >
      {children}
    </TimerContext.Provider>
  );
}

export function useTimer() {
  const ctx = useContext(TimerContext);
  if (!ctx) {
    throw new Error("useTimer deve ser usado dentro de TimerProvider");
  }
  return ctx;
}

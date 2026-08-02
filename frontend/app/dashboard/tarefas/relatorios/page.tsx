"use client";

import { useState } from "react";

import { DashboardPageHeader } from "@/components/dashboard/page-header";
import { TaskReport } from "@/components/tarefas/task-report";
import { TimeReport } from "@/components/tarefas/time-report";
import { Tabs } from "@/components/ui/tabs";

type Tab = "tarefas" | "tempo";

export default function TarefasRelatoriosPage() {
  const [tab, setTab] = useState<Tab>("tarefas");

  return (
    <div className="space-y-5">
      <DashboardPageHeader
        title="Relatórios"
        description={
          tab === "tarefas"
            ? "Todas as tarefas em um só lugar: filtre, priorize e organize seu dia."
            : "Quanto tempo cada projeto/cliente consumiu, o que foi entregue e onde seu tempo está indo."
        }
      />

      <Tabs
        className="no-print"
        value={tab}
        onValueChange={(value) => setTab(value as Tab)}
        items={[
          { value: "tarefas", label: "Tarefas" },
          { value: "tempo", label: "Tempo" },
        ]}
      />

      {tab === "tarefas" ? <TaskReport /> : <TimeReport />}
    </div>
  );
}

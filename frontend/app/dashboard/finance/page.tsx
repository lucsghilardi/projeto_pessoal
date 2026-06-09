"use client";

import { useEffect, useState } from "react";
import Link from "next/link";
import { ArrowDownCircle, ArrowUpCircle, Banknote, ChevronLeft, ChevronRight } from "lucide-react";
import { Cell, Pie, PieChart, ResponsiveContainer, Tooltip as RechartsTooltip } from "recharts";

import { DashboardPageHeader } from "@/components/dashboard/page-header";
import { DashboardPageLoader } from "@/components/dashboard/page-loader";
import { currentMonth, formatCurrency, monthLabel, shiftMonth } from "@/lib/format";
import { appToast } from "@/lib/toast";
import { getFinanceSummary } from "@/services/api";
import { ApiError } from "@/services/apiError";
import type { FinanceSummary } from "@/types/Finance";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";

export default function FinanceDashboardPage() {
  const [month, setMonth] = useState(currentMonth());
  const [summary, setSummary] = useState<FinanceSummary | null>(null);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    let mounted = true;
    setLoading(true);
    (async () => {
      try {
        const data = await getFinanceSummary(month);
        if (mounted) setSummary(data);
      } catch (error) {
        appToast.error(error instanceof ApiError ? error.message : "Não foi possível carregar o painel.");
      } finally {
        if (mounted) setLoading(false);
      }
    })();
    return () => {
      mounted = false;
    };
  }, [month]);

  if (loading && !summary) {
    return <DashboardPageLoader label="Carregando painel financeiro..." />;
  }

  const byCategory = (summary?.by_category ?? []).map((c) => ({ name: c.name, value: c.total, color: c.color }));
  const balance = summary?.balance_month ?? 0;

  return (
    <div className="space-y-6">
      <DashboardPageHeader
        title="Financeiro"
        description="Visão do mês: o que tem nas contas, o que vai pagar e o que vai receber."
      />

      <div className="flex items-center gap-2">
        <Button variant="outline" size="icon" onClick={() => setMonth(shiftMonth(month, -1))}>
          <ChevronLeft className="size-4" />
        </Button>
        <span className="min-w-44 text-center text-sm font-medium">{monthLabel(month)}</span>
        <Button variant="outline" size="icon" onClick={() => setMonth(shiftMonth(month, 1))}>
          <ChevronRight className="size-4" />
        </Button>
      </div>

      <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <Card>
          <CardHeader className="pb-2">
            <CardDescription className="flex items-center gap-1"><Banknote className="size-4" /> Total em contas</CardDescription>
          </CardHeader>
          <CardContent>
            <p className="text-2xl font-semibold tabular-nums">{formatCurrency(summary?.accounts_total ?? 0)}</p>
          </CardContent>
        </Card>
        <Card>
          <CardHeader className="pb-2">
            <CardDescription className="flex items-center gap-1"><ArrowDownCircle className="size-4" /> A pagar (pendente)</CardDescription>
          </CardHeader>
          <CardContent>
            <p className="text-2xl font-semibold tabular-nums text-red-600">{formatCurrency(summary?.payables.pending ?? 0)}</p>
            <p className="text-xs text-muted-foreground">Total do mês: {formatCurrency(summary?.payables.total ?? 0)}</p>
          </CardContent>
        </Card>
        <Card>
          <CardHeader className="pb-2">
            <CardDescription className="flex items-center gap-1"><ArrowUpCircle className="size-4" /> A receber (pendente)</CardDescription>
          </CardHeader>
          <CardContent>
            <p className="text-2xl font-semibold tabular-nums text-amber-600">{formatCurrency(summary?.receivables.pending ?? 0)}</p>
            <p className="text-xs text-muted-foreground">Total do mês: {formatCurrency(summary?.receivables.total ?? 0)}</p>
          </CardContent>
        </Card>
        <Card>
          <CardHeader className="pb-2">
            <CardDescription>Saldo do mês (receber − pagar)</CardDescription>
          </CardHeader>
          <CardContent>
            <p className={`text-2xl font-semibold tabular-nums ${balance >= 0 ? "text-emerald-600" : "text-red-600"}`}>
              {formatCurrency(balance)}
            </p>
          </CardContent>
        </Card>
      </div>

      <div className="grid gap-4 xl:grid-cols-[minmax(0,1fr)_minmax(0,1fr)]">
        <Card>
          <CardHeader>
            <CardTitle>Despesas por categoria</CardTitle>
            <CardDescription>Contas a pagar de {monthLabel(month)}.</CardDescription>
          </CardHeader>
          <CardContent className="h-72">
            {byCategory.length > 0 ? (
              <ResponsiveContainer width="100%" height="100%">
                <PieChart>
                  <Pie data={byCategory} dataKey="value" nameKey="name" innerRadius={50} outerRadius={90} paddingAngle={2}>
                    {byCategory.map((entry, index) => (
                      <Cell key={index} fill={entry.color || "#64748b"} />
                    ))}
                  </Pie>
                  <RechartsTooltip formatter={(v) => formatCurrency(Number(v))} />
                </PieChart>
              </ResponsiveContainer>
            ) : (
              <div className="flex h-full items-center justify-center text-sm text-muted-foreground">
                Sem contas a pagar neste mês.
              </div>
            )}
          </CardContent>
        </Card>

        <Card>
          <CardHeader>
            <CardTitle>Atalhos</CardTitle>
            <CardDescription>Acesse as áreas do Financeiro.</CardDescription>
          </CardHeader>
          <CardContent className="grid gap-2">
            <Link href="/dashboard/finance/payables">
              <Button variant="outline" className="w-full justify-start"><ArrowDownCircle className="size-4" /> Contas a pagar</Button>
            </Link>
            <Link href="/dashboard/finance/receivables">
              <Button variant="outline" className="w-full justify-start"><ArrowUpCircle className="size-4" /> Contas a receber</Button>
            </Link>
            <Link href="/dashboard/finance/accounts">
              <Button variant="outline" className="w-full justify-start"><Banknote className="size-4" /> Contas (saldos)</Button>
            </Link>
          </CardContent>
        </Card>
      </div>
    </div>
  );
}

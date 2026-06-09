"use client";

import { useEffect, useMemo, useState } from "react";
import {
  Bar,
  CartesianGrid,
  ComposedChart,
  Legend,
  Line,
  ResponsiveContainer,
  Tooltip as RechartsTooltip,
  XAxis,
  YAxis,
} from "recharts";

import { DashboardPageHeader } from "@/components/dashboard/page-header";
import { DashboardPageLoader } from "@/components/dashboard/page-loader";
import { appToast } from "@/lib/toast";
import { getFinanceReports } from "@/services/api";
import { ApiError } from "@/services/apiError";
import type { FinanceReport } from "@/types/Finance";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import {
  Table,
  TableBody,
  TableCell,
  TableFooter,
  TableHead,
  TableHeader,
  TableRow,
} from "@/components/ui/table";

function formatCurrency(value: number) {
  return new Intl.NumberFormat("pt-BR", { style: "currency", currency: "BRL" }).format(value);
}

function compactCurrency(value: number) {
  return new Intl.NumberFormat("pt-BR", { notation: "compact", maximumFractionDigits: 1 }).format(value);
}

function currentMonth() {
  return new Date().toISOString().slice(0, 7);
}

function shiftMonth(month: string, delta: number) {
  const [year, mo] = month.split("-").map(Number);
  const d = new Date(year, mo - 1 + delta, 1);
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, "0")}`;
}

// "2026-06" -> "jun/26"
function monthShort(month: string) {
  const [year, mo] = month.split("-").map(Number);
  return new Date(year, mo - 1, 1)
    .toLocaleDateString("pt-BR", { month: "short", year: "2-digit" })
    .replace(".", "");
}

const RANGE_OPTIONS = [
  { value: "3", label: "Últimos 3 meses" },
  { value: "6", label: "Últimos 6 meses" },
  { value: "12", label: "Últimos 12 meses" },
  { value: "24", label: "Últimos 24 meses" },
];

export default function FinanceReportsPage() {
  const [rangeMonths, setRangeMonths] = useState("12");
  const [report, setReport] = useState<FinanceReport | null>(null);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    let mounted = true;
    setLoading(true);
    const end = currentMonth();
    const start = shiftMonth(end, -(Number(rangeMonths) - 1));
    (async () => {
      try {
        const data = await getFinanceReports({ start, end });
        if (mounted) setReport(data);
      } catch (error) {
        appToast.error(error instanceof ApiError ? error.message : "Não foi possível carregar os relatórios.");
      } finally {
        if (mounted) setLoading(false);
      }
    })();
    return () => {
      mounted = false;
    };
  }, [rangeMonths]);

  const months = report?.months ?? [];

  const chartData = useMemo(
    () =>
      (report?.monthly ?? []).map((m) => ({
        month: monthShort(m.month),
        Receita: m.income,
        Despesa: m.expense,
        Saldo: m.balance,
      })),
    [report],
  );

  const totals = useMemo(() => {
    const monthly = report?.monthly ?? [];
    const income = monthly.reduce((acc, m) => acc + m.income, 0);
    const expense = monthly.reduce((acc, m) => acc + m.expense, 0);
    return { income, expense, balance: income - expense };
  }, [report]);

  const cashflow = report?.cashflow_by_category ?? [];
  const incomeCategories = cashflow.filter((c) => c.kind === "receita");
  const expenseCategories = cashflow.filter((c) => c.kind === "despesa");

  if (loading && !report) {
    return <DashboardPageLoader label="Carregando relatórios..." />;
  }

  return (
    <div className="space-y-6">
      <DashboardPageHeader
        title="Relatórios"
        description="Receita x despesa, fluxo de caixa por categoria mês a mês e totais do período."
        actions={
          <Select value={rangeMonths} onValueChange={setRangeMonths}>
            <SelectTrigger className="w-48">
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              {RANGE_OPTIONS.map((opt) => (
                <SelectItem key={opt.value} value={opt.value}>
                  {opt.label}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
        }
      />

      {/* Cards resumo do período */}
      <div className="grid gap-4 sm:grid-cols-3">
        <Card>
          <CardHeader className="pb-2">
            <CardDescription>Receita no período</CardDescription>
          </CardHeader>
          <CardContent>
            <p className="text-2xl font-semibold tabular-nums text-emerald-600">{formatCurrency(totals.income)}</p>
          </CardContent>
        </Card>
        <Card>
          <CardHeader className="pb-2">
            <CardDescription>Despesa no período</CardDescription>
          </CardHeader>
          <CardContent>
            <p className="text-2xl font-semibold tabular-nums text-red-600">{formatCurrency(totals.expense)}</p>
          </CardContent>
        </Card>
        <Card>
          <CardHeader className="pb-2">
            <CardDescription>Saldo no período</CardDescription>
          </CardHeader>
          <CardContent>
            <p
              className={`text-2xl font-semibold tabular-nums ${totals.balance >= 0 ? "text-emerald-600" : "text-red-600"}`}
            >
              {formatCurrency(totals.balance)}
            </p>
          </CardContent>
        </Card>
      </div>

      {/* Despesa x Receita mês a mês */}
      <Card>
        <CardHeader>
          <CardTitle>Despesa x Receita</CardTitle>
          <CardDescription>Comparativo mês a mês, com o saldo (receita − despesa).</CardDescription>
        </CardHeader>
        <CardContent className="h-80">
          {chartData.length > 0 ? (
            <ResponsiveContainer width="100%" height="100%">
              <ComposedChart data={chartData} margin={{ top: 8, right: 8, bottom: 0, left: 8 }}>
                <CartesianGrid strokeDasharray="3 3" vertical={false} className="stroke-muted" />
                <XAxis dataKey="month" tickLine={false} axisLine={false} fontSize={12} />
                <YAxis tickFormatter={compactCurrency} tickLine={false} axisLine={false} width={56} fontSize={12} />
                <RechartsTooltip formatter={(v) => formatCurrency(Number(v))} />
                <Legend />
                <Bar dataKey="Receita" fill="#22c55e" radius={[4, 4, 0, 0]} />
                <Bar dataKey="Despesa" fill="#ef4444" radius={[4, 4, 0, 0]} />
                <Line type="monotone" dataKey="Saldo" stroke="#2563eb" strokeWidth={2} dot={false} />
              </ComposedChart>
            </ResponsiveContainer>
          ) : (
            <div className="flex h-full items-center justify-center text-sm text-muted-foreground">
              Sem dados no período.
            </div>
          )}
        </CardContent>
      </Card>

      {/* Despesas por categoria (fluxo de caixa) mês a mês */}
      <Card>
        <CardHeader>
          <CardTitle>Despesas por categoria (fluxo de caixa)</CardTitle>
          <CardDescription>Quanto cada categoria de despesa consumiu em cada mês.</CardDescription>
        </CardHeader>
        <CardContent>
          {(report?.expense_by_category.length ?? 0) > 0 ? (
            <div className="overflow-x-auto">
              <Table>
                <TableHeader>
                  <TableRow>
                    <TableHead className="sticky left-0 bg-background">Categoria</TableHead>
                    {months.map((m) => (
                      <TableHead key={m} className="text-right whitespace-nowrap">
                        {monthShort(m)}
                      </TableHead>
                    ))}
                    <TableHead className="text-right whitespace-nowrap font-semibold">Total</TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {report?.expense_by_category.map((cat) => (
                    <TableRow key={cat.name}>
                      <TableCell className="sticky left-0 bg-background font-medium">
                        <span className="flex items-center gap-2">
                          <span
                            className="inline-block size-2.5 shrink-0 rounded-full"
                            style={{ backgroundColor: cat.color }}
                          />
                          {cat.name}
                        </span>
                      </TableCell>
                      {months.map((m) => (
                        <TableCell key={m} className="text-right tabular-nums whitespace-nowrap">
                          {cat.monthly[m] ? formatCurrency(cat.monthly[m]) : "—"}
                        </TableCell>
                      ))}
                      <TableCell className="text-right tabular-nums whitespace-nowrap font-semibold">
                        {formatCurrency(cat.total)}
                      </TableCell>
                    </TableRow>
                  ))}
                </TableBody>
                <TableFooter>
                  <TableRow>
                    <TableCell className="sticky left-0 bg-background font-semibold">Total</TableCell>
                    {months.map((m) => {
                      const monthTotal =
                        report?.expense_by_category.reduce((acc, cat) => acc + (cat.monthly[m] ?? 0), 0) ?? 0;
                      return (
                        <TableCell key={m} className="text-right tabular-nums whitespace-nowrap font-semibold">
                          {monthTotal ? formatCurrency(monthTotal) : "—"}
                        </TableCell>
                      );
                    })}
                    <TableCell className="text-right tabular-nums whitespace-nowrap font-semibold">
                      {formatCurrency(totals.expense)}
                    </TableCell>
                  </TableRow>
                </TableFooter>
              </Table>
            </div>
          ) : (
            <p className="py-8 text-center text-sm text-muted-foreground">Sem despesas no período.</p>
          )}
        </CardContent>
      </Card>

      {/* Fluxo de caixa total por categorias */}
      <div className="grid gap-4 lg:grid-cols-2">
        <Card>
          <CardHeader>
            <CardTitle>Receitas por categoria (total)</CardTitle>
            <CardDescription>Fluxo de caixa de entrada acumulado no período.</CardDescription>
          </CardHeader>
          <CardContent>
            {incomeCategories.length > 0 ? (
              <Table>
                <TableHeader>
                  <TableRow>
                    <TableHead>Categoria</TableHead>
                    <TableHead className="text-right">Total</TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {incomeCategories.map((cat) => (
                    <TableRow key={cat.name}>
                      <TableCell className="font-medium">
                        <span className="flex items-center gap-2">
                          <span
                            className="inline-block size-2.5 shrink-0 rounded-full"
                            style={{ backgroundColor: cat.color }}
                          />
                          {cat.name}
                        </span>
                      </TableCell>
                      <TableCell className="text-right tabular-nums text-emerald-600">
                        {formatCurrency(cat.total)}
                      </TableCell>
                    </TableRow>
                  ))}
                </TableBody>
                <TableFooter>
                  <TableRow>
                    <TableCell className="font-semibold">Total</TableCell>
                    <TableCell className="text-right tabular-nums font-semibold text-emerald-600">
                      {formatCurrency(totals.income)}
                    </TableCell>
                  </TableRow>
                </TableFooter>
              </Table>
            ) : (
              <p className="py-8 text-center text-sm text-muted-foreground">Sem receitas no período.</p>
            )}
          </CardContent>
        </Card>

        <Card>
          <CardHeader>
            <CardTitle>Despesas por categoria (total)</CardTitle>
            <CardDescription>Fluxo de caixa de saída acumulado no período.</CardDescription>
          </CardHeader>
          <CardContent>
            {expenseCategories.length > 0 ? (
              <Table>
                <TableHeader>
                  <TableRow>
                    <TableHead>Categoria</TableHead>
                    <TableHead className="text-right">Total</TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {expenseCategories.map((cat) => (
                    <TableRow key={cat.name}>
                      <TableCell className="font-medium">
                        <span className="flex items-center gap-2">
                          <span
                            className="inline-block size-2.5 shrink-0 rounded-full"
                            style={{ backgroundColor: cat.color }}
                          />
                          {cat.name}
                        </span>
                      </TableCell>
                      <TableCell className="text-right tabular-nums text-red-600">
                        {formatCurrency(cat.total)}
                      </TableCell>
                    </TableRow>
                  ))}
                </TableBody>
                <TableFooter>
                  <TableRow>
                    <TableCell className="font-semibold">Total</TableCell>
                    <TableCell className="text-right tabular-nums font-semibold text-red-600">
                      {formatCurrency(totals.expense)}
                    </TableCell>
                  </TableRow>
                </TableFooter>
              </Table>
            ) : (
              <p className="py-8 text-center text-sm text-muted-foreground">Sem despesas no período.</p>
            )}
          </CardContent>
        </Card>
      </div>
    </div>
  );
}

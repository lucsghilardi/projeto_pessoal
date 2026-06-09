"use client";

import { useEffect, useRef, useState } from "react";
import { CreditCard as CreditCardIcon, FileText, Landmark, ScanLine, Sparkles, Upload, X } from "lucide-react";

import { DashboardPageHeader } from "@/components/dashboard/page-header";
import { DashboardPageLoader } from "@/components/dashboard/page-loader";
import { formatCurrency, todayISO } from "@/lib/format";
import { appToast } from "@/lib/toast";
import {
  checkAiReceiptDuplicates,
  confirmAiReceipt,
  confirmAiReceiptBatch,
  getBankAccounts,
  getCreditCards,
  getFinanceCategories,
  parseAiReceipt,
} from "@/services/api";
import { ApiError } from "@/services/apiError";
import type {
  AiReceiptConfirmPayload,
  AiReceiptDestination,
  ReceiptConfidence,
  ReceiptDocumentType,
} from "@/types/AiReceipt";
import type { BankAccount, FinanceCategory } from "@/types/Finance";
import type { CreditCard } from "@/types/CreditCard";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Field, FieldError, FieldGroup, FieldLabel } from "@/components/ui/field";
import { Input } from "@/components/ui/input";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import { Spinner } from "@/components/ui/spinner";
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from "@/components/ui/table";

type ReviewForm = {
  destination: AiReceiptDestination;
  description: string;
  amount: string;
  date: string;
  category_id: string;
  credit_card_id: string;
  installments_total: string;
  bank_account_id: string;
};

type BatchRow = {
  include: boolean;
  description: string;
  amount: string;
  date: string;
  category_id: string;
  duplicate: boolean;
};

const CONFIDENCE_LABELS: Record<ReceiptConfidence, string> = {
  alta: "Confiança alta",
  media: "Confiança média",
  baixa: "Confiança baixa — confira com atenção",
};

function emptyForm(): ReviewForm {
  return {
    destination: "conta",
    description: "",
    amount: "",
    date: todayISO(),
    category_id: "",
    credit_card_id: "",
    installments_total: "1",
    bank_account_id: "",
  };
}

export default function AiReceiptPage() {
  const [categories, setCategories] = useState<FinanceCategory[]>([]);
  const [cards, setCards] = useState<CreditCard[]>([]);
  const [accounts, setAccounts] = useState<BankAccount[]>([]);
  const [loading, setLoading] = useState(true);

  const [file, setFile] = useState<File | null>(null);
  const [previewUrl, setPreviewUrl] = useState<string | null>(null);
  const [analyzing, setAnalyzing] = useState(false);

  const [receiptPath, setReceiptPath] = useState<string | null>(null);
  const [documentType, setDocumentType] = useState<ReceiptDocumentType | null>(null);
  const [confidence, setConfidence] = useState<ReceiptConfidence | null>(null);

  // Modo comprovante (1 lançamento)
  const [form, setForm] = useState<ReviewForm>(emptyForm());

  // Modo lote (fatura de cartão ou extrato de conta)
  const [batchDestination, setBatchDestination] = useState<AiReceiptDestination>("cartao");
  const [batchCardId, setBatchCardId] = useState("");
  const [batchAccountId, setBatchAccountId] = useState("");
  const [rows, setRows] = useState<BatchRow[]>([]);

  const [formError, setFormError] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);

  const fileInputRef = useRef<HTMLInputElement>(null);
  const isPdf = file?.type === "application/pdf";

  useEffect(() => {
    let mounted = true;
    (async () => {
      try {
        const [cats, cardsRes, accRes] = await Promise.all([
          getFinanceCategories("despesa"),
          getCreditCards(),
          getBankAccounts(),
        ]);
        if (mounted) {
          setCategories(cats);
          setCards(cardsRes.credit_cards.filter((c) => c.is_active));
          setAccounts(accRes.accounts);
        }
      } catch (error) {
        appToast.error(error instanceof ApiError ? error.message : "Não foi possível carregar os dados.");
      } finally {
        if (mounted) setLoading(false);
      }
    })();
    return () => {
      mounted = false;
    };
  }, []);

  useEffect(() => {
    return () => {
      if (previewUrl) URL.revokeObjectURL(previewUrl);
    };
  }, [previewUrl]);

  function clearResult() {
    setReceiptPath(null);
    setDocumentType(null);
    setConfidence(null);
    setRows([]);
    setBatchCardId("");
    setBatchAccountId("");
    setFormError(null);
  }

  function handlePickFile(selected: File | null) {
    if (previewUrl) URL.revokeObjectURL(previewUrl);
    clearResult();
    if (!selected) {
      setFile(null);
      setPreviewUrl(null);
      return;
    }
    setFile(selected);
    setPreviewUrl(selected.type.startsWith("image/") ? URL.createObjectURL(selected) : null);
  }

  function resetAll() {
    handlePickFile(null);
    setForm(emptyForm());
    if (fileInputRef.current) fileInputRef.current.value = "";
  }

  async function handleAnalyze() {
    if (!file) return;
    setAnalyzing(true);
    setFormError(null);
    try {
      const result = await parseAiReceipt(file);
      setReceiptPath(result.receipt_path);
      setDocumentType(result.document_type);
      setConfidence(result.items[0]?.confidence ?? null);

      if (result.document_type !== "comprovante") {
        const dest = result.suggestion.destination;
        const cardId =
          result.suggested_card_id != null
            ? String(result.suggested_card_id)
            : cards[0]
              ? String(cards[0].id)
              : "";
        const accId = accounts[0] ? String(accounts[0].id) : "";
        const initialRows: BatchRow[] = result.items.map((it) => ({
          include: !it.duplicate,
          description: it.description ?? "",
          amount: it.amount != null ? String(it.amount) : "",
          date: it.purchase_date ?? todayISO(),
          category_id: it.category_id != null ? String(it.category_id) : "",
          duplicate: it.duplicate,
        }));

        setBatchDestination(dest);
        setBatchCardId(cardId);
        setBatchAccountId(accId);
        setRows(initialRows);

        // Reavalia duplicados contra o destino escolhido (essencial quando o cartão
        // não é auto-detectado, como em CSV sem o número do cartão).
        await refreshDuplicates(initialRows, dest, cardId, accId);
      } else {
        const it = result.items[0];
        setForm({
          destination: result.suggestion.destination,
          description: it?.description ?? "",
          amount: it?.amount != null ? String(it.amount) : "",
          date: it?.purchase_date ?? todayISO(),
          category_id: it?.category_id != null ? String(it.category_id) : "",
          credit_card_id: cards[0] ? String(cards[0].id) : "",
          installments_total: it?.installments_total != null ? String(it.installments_total) : "1",
          bank_account_id: accounts[0] ? String(accounts[0].id) : "",
        });
      }
    } catch (error) {
      appToast.error(error instanceof ApiError ? error.message : "Não foi possível ler o arquivo.");
    } finally {
      setAnalyzing(false);
    }
  }

  function updateRow(index: number, patch: Partial<BatchRow>) {
    setRows((prev) => prev.map((row, i) => (i === index ? { ...row, ...patch } : row)));
  }

  // Reavalia, no servidor, quais linhas já existem no destino selecionado e marca/desmarca.
  async function refreshDuplicates(
    sourceRows: BatchRow[],
    destination: AiReceiptDestination,
    cardId: string,
    accountId: string,
  ) {
    const targetId = destination === "cartao" ? cardId : accountId;
    if (sourceRows.length === 0 || !targetId) return;

    try {
      const res = await checkAiReceiptDuplicates({
        destination,
        credit_card_id: destination === "cartao" ? Number(cardId) : undefined,
        bank_account_id: destination === "conta" ? Number(accountId) : undefined,
        items: sourceRows.map((row) => ({
          description: row.description.trim(),
          amount: Number.parseFloat(row.amount) || 0,
          date: row.date,
        })),
      });
      setRows((prev) =>
        prev.map((row, i) => {
          const dup = res.duplicates[i] ?? false;
          return { ...row, duplicate: dup, include: !dup };
        }),
      );
    } catch {
      // Em caso de falha, mantém os flags atuais.
    }
  }

  function changeBatchDestination(destination: AiReceiptDestination) {
    setBatchDestination(destination);
    void refreshDuplicates(rows, destination, batchCardId, batchAccountId);
  }

  function changeBatchCard(cardId: string) {
    setBatchCardId(cardId);
    void refreshDuplicates(rows, "cartao", cardId, batchAccountId);
  }

  function changeBatchAccount(accountId: string) {
    setBatchAccountId(accountId);
    void refreshDuplicates(rows, "conta", batchCardId, accountId);
  }

  async function handleConfirm(event: React.FormEvent) {
    event.preventDefault();
    setFormError(null);

    if (!receiptPath) return;
    if (!form.description.trim()) {
      setFormError("Informe a descrição.");
      return;
    }
    const amount = Number.parseFloat(form.amount || "0");
    if (!amount || amount <= 0) {
      setFormError("Informe um valor maior que zero.");
      return;
    }

    const payload: AiReceiptConfirmPayload = {
      destination: form.destination,
      receipt_path: receiptPath,
      description: form.description.trim(),
      amount,
      date: form.date,
      category_id: form.category_id ? Number(form.category_id) : null,
    };

    if (form.destination === "cartao") {
      if (!form.credit_card_id) {
        setFormError("Selecione o cartão.");
        return;
      }
      const installments = Number(form.installments_total) || 1;
      payload.credit_card_id = Number(form.credit_card_id);
      payload.installments_total = installments >= 2 ? installments : undefined;
    } else {
      if (!form.bank_account_id) {
        setFormError("Selecione a conta.");
        return;
      }
      payload.bank_account_id = Number(form.bank_account_id);
    }

    setSaving(true);
    try {
      await confirmAiReceipt(payload);
      appToast.success(
        form.destination === "cartao" ? "Lançado no cartão." : "Lançado e saldo da conta debitado.",
      );
      resetAll();
    } catch (error) {
      const message = error instanceof ApiError ? error.message : "Não foi possível salvar o lançamento.";
      setFormError(message);
      appToast.error(message);
    } finally {
      setSaving(false);
    }
  }

  async function handleConfirmBatch() {
    setFormError(null);
    if (!receiptPath) return;

    if (batchDestination === "cartao" && !batchCardId) {
      setFormError("Selecione o cartão.");
      return;
    }
    if (batchDestination === "conta" && !batchAccountId) {
      setFormError("Selecione a conta.");
      return;
    }

    const selected = rows.filter((row) => row.include);
    if (selected.length === 0) {
      setFormError("Selecione ao menos um lançamento.");
      return;
    }

    for (const row of selected) {
      if (!row.description.trim() || !(Number.parseFloat(row.amount || "0") > 0) || !row.date) {
        setFormError("Há lançamentos selecionados com descrição, valor ou data inválidos.");
        return;
      }
    }

    setSaving(true);
    try {
      const result = await confirmAiReceiptBatch({
        destination: batchDestination,
        receipt_path: receiptPath,
        credit_card_id: batchDestination === "cartao" ? Number(batchCardId) : undefined,
        bank_account_id: batchDestination === "conta" ? Number(batchAccountId) : undefined,
        items: selected.map((row) => ({
          description: row.description.trim(),
          amount: Number.parseFloat(row.amount),
          date: row.date,
          category_id: row.category_id ? Number(row.category_id) : null,
        })),
      });
      appToast.success(result.message);
      resetAll();
    } catch (error) {
      const message = error instanceof ApiError ? error.message : "Não foi possível importar os lançamentos.";
      setFormError(message);
      appToast.error(message);
    } finally {
      setSaving(false);
    }
  }

  if (loading) {
    return <DashboardPageLoader label="Carregando lançamento via IA..." />;
  }

  const selectedCount = rows.filter((r) => r.include).length;
  const selectedTotal = rows
    .filter((r) => r.include)
    .reduce((sum, r) => sum + (Number.parseFloat(r.amount) || 0), 0);
  const isBatch = documentType === "fatura" || documentType === "extrato";

  return (
    <div className="space-y-6">
      <DashboardPageHeader
        title="Lançar via IA"
        description="Envie a foto de um comprovante (pix, débito ou cartão) ou o PDF de uma fatura. A IA lê os lançamentos e sugere as categorias; você confere, ignora duplicados e confirma."
      />

      <div className={isBatch ? "space-y-6" : "grid gap-6 lg:grid-cols-2"}>
        {/* Upload */}
        <Card>
          <CardHeader>
            <CardTitle>Arquivo</CardTitle>
            <CardDescription>Foto de comprovante ou PDF de fatura de cartão.</CardDescription>
          </CardHeader>
          <CardContent className="space-y-4">
            <input
              ref={fileInputRef}
              type="file"
              accept="image/*,application/pdf,.csv,.ofx,.qfx,text/csv"
              capture="environment"
              className="hidden"
              onChange={(e) => handlePickFile(e.target.files?.[0] ?? null)}
            />

            {previewUrl ? (
              <div className="relative overflow-hidden rounded-lg border border-border">
                {/* eslint-disable-next-line @next/next/no-img-element */}
                <img
                  src={previewUrl}
                  alt="Pré-visualização do comprovante"
                  className="max-h-80 w-full bg-muted/30 object-contain"
                />
                <Button
                  type="button"
                  variant="secondary"
                  size="icon"
                  className="absolute right-2 top-2"
                  onClick={resetAll}
                  aria-label="Remover arquivo"
                >
                  <X className="size-4" />
                </Button>
              </div>
            ) : file ? (
              <div className="flex items-center justify-between gap-3 rounded-lg border border-border bg-muted/20 px-4 py-3">
                <div className="flex min-w-0 items-center gap-2 text-sm">
                  <FileText className="size-5 shrink-0 text-muted-foreground" />
                  <span className="truncate">{file.name}</span>
                </div>
                <Button type="button" variant="ghost" size="icon" onClick={resetAll} aria-label="Remover arquivo">
                  <X className="size-4" />
                </Button>
              </div>
            ) : (
              <button
                type="button"
                onClick={() => fileInputRef.current?.click()}
                className="flex w-full flex-col items-center justify-center gap-2 rounded-lg border border-dashed border-border bg-muted/20 px-4 py-12 text-sm text-muted-foreground transition-colors hover:bg-muted/40"
              >
                <Upload className="size-6" />
                <span>Toque para enviar foto, imagem ou PDF</span>
              </button>
            )}

            <div className="flex flex-wrap gap-3">
              <Button type="button" variant="outline" onClick={() => fileInputRef.current?.click()}>
                <Upload className="size-4" />
                {file ? "Trocar arquivo" : "Escolher arquivo"}
              </Button>
              <Button type="button" onClick={handleAnalyze} disabled={!file || analyzing}>
                {analyzing ? <Spinner data-icon="inline-start" /> : <Sparkles className="size-4" />}
                {isPdf ? "Ler fatura" : "Analisar"}
              </Button>
            </div>
          </CardContent>
        </Card>

        {/* Modo comprovante: revisão de 1 lançamento */}
        {!isBatch ? (
          <Card>
            <CardHeader>
              <CardTitle>Revisar e confirmar</CardTitle>
              <CardDescription>
                {receiptPath
                  ? "Confira os dados lidos, ajuste o que precisar e confirme o lançamento."
                  : "Analise um arquivo para preencher os campos automaticamente."}
              </CardDescription>
            </CardHeader>
            <CardContent>
              {receiptPath ? (
                <form onSubmit={handleConfirm}>
                  <FieldGroup className="gap-4">
                    {confidence ? (
                      <div className="flex items-center gap-2 rounded-md border border-border bg-muted/30 px-3 py-2 text-xs text-muted-foreground">
                        <ScanLine className="size-4 shrink-0" />
                        <span>{CONFIDENCE_LABELS[confidence]}</span>
                      </div>
                    ) : null}

                    <Field>
                      <FieldLabel>Onde lançar</FieldLabel>
                      <div className="grid grid-cols-2 gap-2">
                        <Button
                          type="button"
                          variant={form.destination === "cartao" ? "default" : "outline"}
                          onClick={() => setForm({ ...form, destination: "cartao" })}
                        >
                          <CreditCardIcon className="size-4" />
                          Cartão de crédito
                        </Button>
                        <Button
                          type="button"
                          variant={form.destination === "conta" ? "default" : "outline"}
                          onClick={() => setForm({ ...form, destination: "conta" })}
                        >
                          <Landmark className="size-4" />
                          Conta (débito/pix)
                        </Button>
                      </div>
                    </Field>

                    {form.destination === "cartao" ? (
                      <div className="grid grid-cols-2 gap-3">
                        <Field>
                          <FieldLabel htmlFor="card">Cartão</FieldLabel>
                          <Select
                            value={form.credit_card_id}
                            onValueChange={(value) => setForm({ ...form, credit_card_id: value })}
                          >
                            <SelectTrigger id="card">
                              <SelectValue placeholder="Selecione" />
                            </SelectTrigger>
                            <SelectContent>
                              {cards.map((card) => (
                                <SelectItem key={card.id} value={String(card.id)}>{card.name}</SelectItem>
                              ))}
                            </SelectContent>
                          </Select>
                        </Field>
                        <Field>
                          <FieldLabel htmlFor="installments">Parcelas (1 = à vista)</FieldLabel>
                          <Input
                            id="installments"
                            type="number"
                            min="1"
                            max="360"
                            value={form.installments_total}
                            onChange={(e) => setForm({ ...form, installments_total: e.target.value })}
                          />
                        </Field>
                      </div>
                    ) : (
                      <Field>
                        <FieldLabel htmlFor="account">Conta (será debitada)</FieldLabel>
                        <Select
                          value={form.bank_account_id}
                          onValueChange={(value) => setForm({ ...form, bank_account_id: value })}
                        >
                          <SelectTrigger id="account">
                            <SelectValue placeholder="Selecione a conta" />
                          </SelectTrigger>
                          <SelectContent>
                            {accounts.map((account) => (
                              <SelectItem key={account.id} value={String(account.id)}>{account.name}</SelectItem>
                            ))}
                          </SelectContent>
                        </Select>
                      </Field>
                    )}

                    <Field>
                      <FieldLabel htmlFor="description">Descrição</FieldLabel>
                      <Input
                        id="description"
                        value={form.description}
                        onChange={(e) => setForm({ ...form, description: e.target.value })}
                        placeholder="Ex.: Mercado, Posto, Farmácia"
                      />
                    </Field>

                    <Field>
                      <FieldLabel htmlFor="category">Categoria</FieldLabel>
                      <Select
                        value={form.category_id || "__none__"}
                        onValueChange={(value) => setForm({ ...form, category_id: value === "__none__" ? "" : value })}
                      >
                        <SelectTrigger id="category">
                          <SelectValue placeholder="Selecione" />
                        </SelectTrigger>
                        <SelectContent>
                          <SelectItem value="__none__">Sem categoria</SelectItem>
                          {categories.map((category) => (
                            <SelectItem key={category.id} value={String(category.id)}>{category.name}</SelectItem>
                          ))}
                        </SelectContent>
                      </Select>
                    </Field>

                    <div className="grid grid-cols-2 gap-3">
                      <Field>
                        <FieldLabel htmlFor="amount">
                          {form.destination === "cartao" && Number(form.installments_total) >= 2
                            ? "Valor da parcela (R$)"
                            : "Valor (R$)"}
                        </FieldLabel>
                        <Input
                          id="amount"
                          type="number"
                          step="0.01"
                          min="0"
                          value={form.amount}
                          onChange={(e) => setForm({ ...form, amount: e.target.value })}
                          placeholder="0,00"
                        />
                      </Field>
                      <Field>
                        <FieldLabel htmlFor="date">Data</FieldLabel>
                        <Input
                          id="date"
                          type="date"
                          value={form.date}
                          onChange={(e) => setForm({ ...form, date: e.target.value })}
                        />
                      </Field>
                    </div>

                    {form.destination === "conta" && accounts.length === 0 ? (
                      <p className="text-xs text-muted-foreground">
                        Cadastre uma conta em “Contas (saldos)” primeiro.
                      </p>
                    ) : null}
                    {form.destination === "cartao" && cards.length === 0 ? (
                      <p className="text-xs text-muted-foreground">
                        Cadastre um cartão em “Cartões de crédito” primeiro.
                      </p>
                    ) : null}

                    <FieldError>{formError}</FieldError>
                  </FieldGroup>

                  <div className="mt-6 flex justify-end">
                    <Button type="submit" disabled={saving}>
                      {saving ? <Spinner data-icon="inline-start" /> : null}
                      Confirmar lançamento
                    </Button>
                  </div>
                </form>
              ) : (
                <div className="flex min-h-[220px] flex-col items-center justify-center gap-2 text-center text-sm text-muted-foreground">
                  <Sparkles className="size-6" />
                  <span>Os dados lidos pela IA aparecerão aqui para você revisar.</span>
                </div>
              )}
            </CardContent>
          </Card>
        ) : null}

        {/* Modo lote: tabela de vários lançamentos (fatura de cartão ou extrato de conta) */}
        {isBatch ? (
          <Card>
            <CardHeader>
              <CardTitle>{documentType === "extrato" ? "Lançamentos do extrato" : "Lançamentos da fatura"}</CardTitle>
              <CardDescription>
                Marque os que deseja lançar; os já existentes vêm desmarcados e são ignorados automaticamente.
                {batchDestination === "conta"
                  ? " Importar para uma conta não altera o saldo (o extrato já o reflete)."
                  : ""}
              </CardDescription>
            </CardHeader>
            <CardContent className="space-y-4">
              <div className="flex flex-col gap-3 sm:flex-row sm:items-end">
                <Field className="sm:max-w-xs">
                  <FieldLabel>Onde lançar</FieldLabel>
                  <div className="grid grid-cols-2 gap-2">
                    <Button
                      type="button"
                      variant={batchDestination === "cartao" ? "default" : "outline"}
                      onClick={() => changeBatchDestination("cartao")}
                    >
                      <CreditCardIcon className="size-4" />
                      Cartão
                    </Button>
                    <Button
                      type="button"
                      variant={batchDestination === "conta" ? "default" : "outline"}
                      onClick={() => changeBatchDestination("conta")}
                    >
                      <Landmark className="size-4" />
                      Conta
                    </Button>
                  </div>
                </Field>

                {batchDestination === "cartao" ? (
                  <Field className="sm:max-w-xs">
                    <FieldLabel htmlFor="batch-card">Cartão</FieldLabel>
                    <Select value={batchCardId} onValueChange={changeBatchCard}>
                      <SelectTrigger id="batch-card">
                        <SelectValue placeholder="Selecione o cartão" />
                      </SelectTrigger>
                      <SelectContent>
                        {cards.map((card) => (
                          <SelectItem key={card.id} value={String(card.id)}>{card.name}</SelectItem>
                        ))}
                      </SelectContent>
                    </Select>
                  </Field>
                ) : (
                  <Field className="sm:max-w-xs">
                    <FieldLabel htmlFor="batch-account">Conta</FieldLabel>
                    <Select value={batchAccountId} onValueChange={changeBatchAccount}>
                      <SelectTrigger id="batch-account">
                        <SelectValue placeholder="Selecione a conta" />
                      </SelectTrigger>
                      <SelectContent>
                        {accounts.map((account) => (
                          <SelectItem key={account.id} value={String(account.id)}>{account.name}</SelectItem>
                        ))}
                      </SelectContent>
                    </Select>
                  </Field>
                )}
              </div>

              <div className="overflow-x-auto rounded-lg border border-border">
                <Table>
                  <TableHeader>
                    <TableRow>
                      <TableHead className="w-10"></TableHead>
                      <TableHead>Descrição</TableHead>
                      <TableHead className="w-40">Categoria</TableHead>
                      <TableHead className="w-28">Valor</TableHead>
                      <TableHead className="w-36">Data</TableHead>
                      <TableHead className="w-24">Status</TableHead>
                    </TableRow>
                  </TableHeader>
                  <TableBody>
                    {rows.map((row, index) => (
                      <TableRow key={index} className={row.include ? "" : "opacity-60"}>
                        <TableCell>
                          <input
                            type="checkbox"
                            className="size-4 accent-primary"
                            checked={row.include}
                            onChange={(e) => updateRow(index, { include: e.target.checked })}
                            aria-label="Incluir lançamento"
                          />
                        </TableCell>
                        <TableCell>
                          <Input
                            value={row.description}
                            onChange={(e) => updateRow(index, { description: e.target.value })}
                            className="min-w-44"
                          />
                        </TableCell>
                        <TableCell>
                          <Select
                            value={row.category_id || "__none__"}
                            onValueChange={(value) => updateRow(index, { category_id: value === "__none__" ? "" : value })}
                          >
                            <SelectTrigger>
                              <SelectValue placeholder="—" />
                            </SelectTrigger>
                            <SelectContent>
                              <SelectItem value="__none__">Sem categoria</SelectItem>
                              {categories.map((category) => (
                                <SelectItem key={category.id} value={String(category.id)}>{category.name}</SelectItem>
                              ))}
                            </SelectContent>
                          </Select>
                        </TableCell>
                        <TableCell>
                          <Input
                            type="number"
                            step="0.01"
                            min="0"
                            value={row.amount}
                            onChange={(e) => updateRow(index, { amount: e.target.value })}
                            className="w-24"
                          />
                        </TableCell>
                        <TableCell>
                          <Input
                            type="date"
                            value={row.date}
                            onChange={(e) => updateRow(index, { date: e.target.value })}
                            className="w-36"
                          />
                        </TableCell>
                        <TableCell>
                          {row.duplicate ? (
                            <span className="inline-flex items-center rounded-full bg-amber-500/15 px-2 py-0.5 text-xs font-medium text-amber-600 dark:text-amber-400">
                              Já lançado
                            </span>
                          ) : (
                            <span className="inline-flex items-center rounded-full bg-emerald-500/15 px-2 py-0.5 text-xs font-medium text-emerald-600 dark:text-emerald-400">
                              Novo
                            </span>
                          )}
                        </TableCell>
                      </TableRow>
                    ))}
                  </TableBody>
                </Table>
              </div>

              {batchDestination === "cartao" && cards.length === 0 ? (
                <p className="text-xs text-muted-foreground">
                  Cadastre um cartão em “Cartões de crédito” primeiro.
                </p>
              ) : null}
              {batchDestination === "conta" && accounts.length === 0 ? (
                <p className="text-xs text-muted-foreground">
                  Cadastre uma conta em “Contas (saldos)” primeiro.
                </p>
              ) : null}

              {formError ? <p className="text-sm text-destructive">{formError}</p> : null}

              <div className="flex flex-col items-stretch gap-3 sm:flex-row sm:items-center sm:justify-between">
                <p className="text-sm text-muted-foreground">
                  {selectedCount} selecionado(s) · {formatCurrency(selectedTotal)}
                </p>
                <Button type="button" onClick={handleConfirmBatch} disabled={saving || selectedCount === 0}>
                  {saving ? <Spinner data-icon="inline-start" /> : null}
                  Lançar selecionados
                </Button>
              </div>
            </CardContent>
          </Card>
        ) : null}
      </div>
    </div>
  );
}

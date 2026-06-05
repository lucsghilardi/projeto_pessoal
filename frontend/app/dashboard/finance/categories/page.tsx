"use client";

import { useEffect, useState } from "react";
import { Pencil, Plus, Trash2 } from "lucide-react";

import { DashboardPageHeader } from "@/components/dashboard/page-header";
import { DashboardPageLoader } from "@/components/dashboard/page-loader";
import { appToast } from "@/lib/toast";
import {
  createFinanceCategory,
  deleteFinanceCategory,
  getFinanceCategories,
  updateFinanceCategory,
} from "@/services/api";
import { ApiError } from "@/services/apiError";
import type { FinanceCategory, FinanceCategoryKind } from "@/types/Finance";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { Field, FieldError, FieldGroup, FieldLabel } from "@/components/ui/field";
import { Input } from "@/components/ui/input";
import {
  Sheet,
  SheetContent,
  SheetDescription,
  SheetFooter,
  SheetHeader,
  SheetTitle,
} from "@/components/ui/sheet";
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

const KIND_LABELS: Record<FinanceCategoryKind, string> = {
  despesa: "Despesa",
  receita: "Receita",
};

export default function FinanceCategoriesPage() {
  const [categories, setCategories] = useState<FinanceCategory[]>([]);
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [isSheetOpen, setIsSheetOpen] = useState(false);
  const [editing, setEditing] = useState<FinanceCategory | null>(null);
  const [name, setName] = useState("");
  const [kind, setKind] = useState<FinanceCategoryKind>("despesa");
  const [color, setColor] = useState("#64748b");
  const [formError, setFormError] = useState<string | null>(null);

  async function load() {
    setCategories(await getFinanceCategories());
  }

  useEffect(() => {
    let mounted = true;
    (async () => {
      try {
        const data = await getFinanceCategories();
        if (mounted) setCategories(data);
      } catch (error) {
        appToast.error(error instanceof ApiError ? error.message : "Não foi possível carregar as categorias.");
      } finally {
        if (mounted) setLoading(false);
      }
    })();
    return () => {
      mounted = false;
    };
  }, []);

  function openCreate() {
    setEditing(null);
    setName("");
    setKind("despesa");
    setColor("#64748b");
    setFormError(null);
    setIsSheetOpen(true);
  }

  function openEdit(category: FinanceCategory) {
    setEditing(category);
    setName(category.name);
    setKind(category.kind);
    setColor(category.color || "#64748b");
    setFormError(null);
    setIsSheetOpen(true);
  }

  async function handleSubmit(event: React.FormEvent) {
    event.preventDefault();
    setFormError(null);
    if (!name.trim()) {
      setFormError("Informe o nome da categoria.");
      return;
    }
    setSaving(true);
    try {
      if (editing) {
        await updateFinanceCategory(editing.id, { name: name.trim(), kind, color });
        appToast.success("Categoria atualizada.");
      } else {
        await createFinanceCategory({ name: name.trim(), kind, color });
        appToast.success("Categoria criada.");
      }
      setIsSheetOpen(false);
      await load();
    } catch (error) {
      const message = error instanceof ApiError ? error.message : "Não foi possível salvar a categoria.";
      setFormError(message);
      appToast.error(message);
    } finally {
      setSaving(false);
    }
  }

  async function handleDelete(category: FinanceCategory) {
    if (!window.confirm(`Excluir a categoria "${category.name}"?`)) return;
    try {
      await deleteFinanceCategory(category.id);
      appToast.success("Categoria removida.");
      await load();
    } catch (error) {
      appToast.error(error instanceof ApiError ? error.message : "Não foi possível remover a categoria.");
    }
  }

  if (loading) {
    return <DashboardPageLoader label="Carregando categorias..." />;
  }

  return (
    <div className="space-y-6">
      <DashboardPageHeader
        title="Categorias"
        description="Organize despesas e receitas. Edite, adicione ou remova como quiser."
        actions={
          <Button onClick={openCreate}>
            <Plus className="size-4" />
            Nova categoria
          </Button>
        }
      />

      <Card>
        <CardHeader>
          <CardTitle>Minhas categorias</CardTitle>
          <CardDescription>{categories.length} cadastrada(s).</CardDescription>
        </CardHeader>
        <CardContent>
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>Categoria</TableHead>
                <TableHead>Tipo</TableHead>
                <TableHead className="text-right">Ações</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {categories.map((category) => (
                <TableRow key={category.id}>
                  <TableCell>
                    <span className="inline-flex items-center gap-2 text-sm">
                      <span className="size-3 rounded-full" style={{ backgroundColor: category.color }} />
                      {category.name}
                    </span>
                  </TableCell>
                  <TableCell>
                    <span className={`rounded-full border px-2 py-0.5 text-xs ${
                      category.kind === "receita"
                        ? "border-emerald-200 bg-emerald-50 text-emerald-700"
                        : "border-zinc-200 bg-zinc-100 text-zinc-700"
                    }`}>
                      {KIND_LABELS[category.kind]}
                    </span>
                  </TableCell>
                  <TableCell className="text-right">
                    <div className="flex justify-end gap-1">
                      <Button variant="ghost" size="icon" onClick={() => openEdit(category)}>
                        <Pencil className="size-4" />
                      </Button>
                      <Button variant="ghost" size="icon" onClick={() => handleDelete(category)}>
                        <Trash2 className="size-4 text-red-600" />
                      </Button>
                    </div>
                  </TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        </CardContent>
      </Card>

      <Sheet open={isSheetOpen} onOpenChange={setIsSheetOpen}>
        <SheetContent className="w-full sm:max-w-md">
          <SheetHeader>
            <SheetTitle>{editing ? "Editar categoria" : "Nova categoria"}</SheetTitle>
            <SheetDescription>Nome, tipo (despesa/receita) e cor.</SheetDescription>
          </SheetHeader>
          <form className="px-4 pb-4" onSubmit={handleSubmit}>
            <FieldGroup className="gap-4">
              <Field>
                <FieldLabel htmlFor="category-name">Nome</FieldLabel>
                <Input id="category-name" value={name} onChange={(e) => setName(e.target.value)}
                  placeholder="Ex.: Mercado" required />
              </Field>
              <Field>
                <FieldLabel htmlFor="category-kind">Tipo</FieldLabel>
                <Select value={kind} onValueChange={(value) => setKind(value as FinanceCategoryKind)}>
                  <SelectTrigger id="category-kind">
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="despesa">Despesa</SelectItem>
                    <SelectItem value="receita">Receita</SelectItem>
                  </SelectContent>
                </Select>
              </Field>
              <Field>
                <FieldLabel htmlFor="category-color">Cor</FieldLabel>
                <div className="flex items-center gap-3">
                  <input id="category-color" type="color" value={color}
                    onChange={(e) => setColor(e.target.value)}
                    className="h-10 w-14 cursor-pointer rounded border" />
                  <span className="text-sm text-muted-foreground">{color}</span>
                </div>
              </Field>
              <FieldError>{formError}</FieldError>
            </FieldGroup>
            <SheetFooter className="px-0">
              <Button type="submit" disabled={saving}>
                {saving ? <Spinner data-icon="inline-start" /> : null}
                {editing ? "Salvar alterações" : "Criar categoria"}
              </Button>
            </SheetFooter>
          </form>
        </SheetContent>
      </Sheet>
    </div>
  );
}

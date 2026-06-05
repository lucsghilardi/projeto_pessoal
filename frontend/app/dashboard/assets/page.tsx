"use client";

import { useEffect, useState } from "react";
import { Plus, Trash2 } from "lucide-react";

import { DashboardPageHeader } from "@/components/dashboard/page-header";
import { DashboardPageLoader } from "@/components/dashboard/page-loader";
import { appToast } from "@/lib/toast";
import { createAsset, deleteAsset, getAssets, updateAsset } from "@/services/api";
import { ApiError } from "@/services/apiError";
import type { Asset } from "@/types/Asset";
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
import { Spinner } from "@/components/ui/spinner";

function formatCurrency(value: number) {
  return new Intl.NumberFormat("pt-BR", { style: "currency", currency: "BRL" }).format(value);
}

function toNumber(value: string) {
  return Number.parseFloat(value || "0");
}

export default function AssetsPage() {
  const [assets, setAssets] = useState<Asset[]>([]);
  const [values, setValues] = useState<Record<number, string>>({});
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [isSheetOpen, setIsSheetOpen] = useState(false);
  const [newName, setNewName] = useState("");
  const [newValue, setNewValue] = useState("");
  const [formError, setFormError] = useState<string | null>(null);

  function hydrate(list: Asset[]) {
    setAssets(list);
    const map: Record<number, string> = {};
    list.forEach((asset) => {
      map[asset.id] = String(asset.value);
    });
    setValues(map);
  }

  async function load() {
    const data = await getAssets();
    hydrate(data.assets);
  }

  useEffect(() => {
    let mounted = true;
    (async () => {
      try {
        const data = await getAssets();
        if (mounted) hydrate(data.assets);
      } catch (error) {
        appToast.error(error instanceof ApiError ? error.message : "Não foi possível carregar os patrimônios.");
      } finally {
        if (mounted) setLoading(false);
      }
    })();
    return () => {
      mounted = false;
    };
  }, []);

  const total = assets.reduce((sum, asset) => sum + toNumber(values[asset.id] ?? "0"), 0);

  async function handleSaveValues() {
    setSaving(true);
    try {
      await Promise.all(
        assets.map((asset) =>
          updateAsset(asset.id, {
            name: asset.name,
            value: toNumber(values[asset.id] ?? "0"),
          }),
        ),
      );
      appToast.success("Valores atualizados.");
      await load();
    } catch (error) {
      appToast.error(error instanceof ApiError ? error.message : "Não foi possível salvar os valores.");
    } finally {
      setSaving(false);
    }
  }

  async function handleCreate(event: React.FormEvent) {
    event.preventDefault();
    setFormError(null);
    if (!newName.trim()) {
      setFormError("Informe o nome do patrimônio.");
      return;
    }
    try {
      await createAsset({ name: newName.trim(), value: toNumber(newValue) });
      appToast.success("Patrimônio adicionado.");
      setIsSheetOpen(false);
      setNewName("");
      setNewValue("");
      await load();
    } catch (error) {
      const message = error instanceof ApiError ? error.message : "Não foi possível adicionar o patrimônio.";
      setFormError(message);
      appToast.error(message);
    }
  }

  async function handleDelete(asset: Asset) {
    if (!window.confirm(`Excluir "${asset.name}"?`)) return;
    try {
      await deleteAsset(asset.id);
      appToast.success("Patrimônio removido.");
      await load();
    } catch (error) {
      appToast.error(error instanceof ApiError ? error.message : "Não foi possível remover o patrimônio.");
    }
  }

  if (loading) {
    return <DashboardPageLoader label="Carregando patrimônios..." />;
  }

  return (
    <div className="space-y-6">
      <DashboardPageHeader
        title="Patrimônios"
        description="As coisas de valor que você tem e poderia vender se precisar. Edite os valores e clique em salvar."
        actions={
          <Button variant="outline" onClick={() => setIsSheetOpen(true)}>
            <Plus className="size-4" />
            Novo patrimônio
          </Button>
        }
      />

      <Card>
        <CardHeader className="pb-2">
          <CardDescription>Valor total estimado</CardDescription>
        </CardHeader>
        <CardContent>
          <p className="text-3xl font-semibold tabular-nums text-emerald-600">{formatCurrency(total)}</p>
        </CardContent>
      </Card>

      <Card>
        <CardHeader>
          <CardTitle>Meus patrimônios</CardTitle>
          <CardDescription>{assets.length} item(ns).</CardDescription>
        </CardHeader>
        <CardContent className="space-y-3">
          {assets.length === 0 ? (
            <p className="py-6 text-center text-sm text-muted-foreground">
              Nenhum patrimônio. Clique em “Novo patrimônio”.
            </p>
          ) : (
            assets.map((asset) => (
              <div key={asset.id} className="flex items-center gap-3">
                <div className="min-w-0 flex-1 text-sm font-medium">{asset.name}</div>
                <div className="flex items-center gap-1">
                  <span className="text-sm text-muted-foreground">R$</span>
                  <Input
                    type="number"
                    step="0.01"
                    className="w-40"
                    value={values[asset.id] ?? ""}
                    onChange={(e) => setValues((prev) => ({ ...prev, [asset.id]: e.target.value }))}
                  />
                </div>
                <Button variant="ghost" size="icon" title="Excluir" onClick={() => handleDelete(asset)}>
                  <Trash2 className="size-4 text-red-600" />
                </Button>
              </div>
            ))
          )}

          {assets.length > 0 ? (
            <div className="pt-2">
              <Button onClick={handleSaveValues} disabled={saving}>
                {saving ? <Spinner data-icon="inline-start" /> : null}
                Salvar valores
              </Button>
            </div>
          ) : null}
        </CardContent>
      </Card>

      <Sheet open={isSheetOpen} onOpenChange={setIsSheetOpen}>
        <SheetContent className="w-full sm:max-w-md">
          <SheetHeader>
            <SheetTitle>Novo patrimônio</SheetTitle>
            <SheetDescription>Algo de valor que você possui (carro, eletrônicos, etc.).</SheetDescription>
          </SheetHeader>
          <form className="px-4 pb-4" onSubmit={handleCreate}>
            <FieldGroup className="gap-4">
              <Field>
                <FieldLabel htmlFor="asset-name">Nome</FieldLabel>
                <Input id="asset-name" value={newName} onChange={(e) => setNewName(e.target.value)}
                  placeholder="Ex.: Carro, Macbook, iPhone 16 Pro" required />
              </Field>
              <Field>
                <FieldLabel htmlFor="asset-value">Valor (R$)</FieldLabel>
                <Input id="asset-value" type="number" step="0.01" value={newValue}
                  onChange={(e) => setNewValue(e.target.value)} placeholder="0,00" />
              </Field>
              <FieldError>{formError}</FieldError>
            </FieldGroup>
            <SheetFooter className="px-0">
              <Button type="submit">Adicionar</Button>
            </SheetFooter>
          </form>
        </SheetContent>
      </Sheet>
    </div>
  );
}

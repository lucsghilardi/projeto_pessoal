"use client";

import { useEffect, useRef, useState } from "react";
import { ImagePlus, Sparkles, X } from "lucide-react";

import { todayISO } from "@/lib/format";
import { appToast } from "@/lib/toast";
import { cn } from "@/lib/utils";
import {
  analisarSaudeRefeicao,
  createSaudeRefeicao,
  saudeRefeicaoFotoUrl,
  updateSaudeRefeicao,
} from "@/services/api";
import { ApiError } from "@/services/apiError";
import type {
  SaudeRefeicao,
  SaudeRefeicaoAnalise,
  SaudeRefeicaoTipo,
} from "@/types/Saude";
import { Button } from "@/components/ui/button";
import { Field, FieldDescription, FieldError, FieldGroup, FieldLabel } from "@/components/ui/field";
import { Input } from "@/components/ui/input";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import {
  Sheet,
  SheetContent,
  SheetDescription,
  SheetFooter,
  SheetHeader,
  SheetTitle,
} from "@/components/ui/sheet";
import { Spinner } from "@/components/ui/spinner";

const TIPOS: Array<{ value: SaudeRefeicaoTipo; label: string }> = [
  { value: "cafe_da_manha", label: "Café da manhã" },
  { value: "almoco", label: "Almoço" },
  { value: "jantar", label: "Jantar" },
  { value: "lanche", label: "Lanche" },
  { value: "outro", label: "Outro" },
];

const CONFIANCA_LABEL: Record<string, string> = {
  alta: "confiança alta",
  media: "confiança média",
  baixa: "confiança baixa",
};

const MAX_FOTO_MB = 10;

function horaAgora() {
  const d = new Date();
  return `${String(d.getHours()).padStart(2, "0")}:${String(d.getMinutes()).padStart(2, "0")}`;
}

function paraCampo(valor: number | null | undefined) {
  return valor === null || valor === undefined ? "" : String(valor);
}

function tamanhoArquivo(bytes: number) {
  return bytes < 1024 * 1024
    ? `${Math.max(1, Math.round(bytes / 1024))} KB`
    : `${(bytes / 1024 / 1024).toFixed(1)} MB`;
}

type Props = {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  editing: SaudeRefeicao | null;
  onSaved: () => void;
  /** Dia sugerido para um registro novo — o dia que a tela está exibindo. */
  defaultData?: string;
};

export function RefeicaoSheet({
  open,
  onOpenChange,
  editing,
  onSaved,
  defaultData,
}: Props) {
  const [data, setData] = useState(defaultData ?? todayISO());
  const [horario, setHorario] = useState(horaAgora());
  const [nome, setNome] = useState("");
  const [tipo, setTipo] = useState<string>("outro");
  const [calorias, setCalorias] = useState("");
  const [proteinas, setProteinas] = useState("");
  const [carboidratos, setCarboidratos] = useState("");
  const [gorduras, setGorduras] = useState("");
  const [observacao, setObservacao] = useState("");
  const [saving, setSaving] = useState(false);
  const [formError, setFormError] = useState<string | null>(null);

  // Assistente de IA: descrição e/ou foto entram, estimativa sai — e o usuário
  // confere os campos antes de confirmar.
  const [descricao, setDescricao] = useState("");
  const [analisando, setAnalisando] = useState(false);
  const [analise, setAnalise] = useState<SaudeRefeicaoAnalise | null>(null);
  const [foto, setFoto] = useState<File | null>(null);
  const [fotoPreview, setFotoPreview] = useState<string | null>(null);
  const [removerFoto, setRemoverFoto] = useState(false);
  const fotoInputRef = useRef<HTMLInputElement>(null);

  const fotoAtual = editing?.foto_path && !removerFoto ? saudeRefeicaoFotoUrl(editing.id) : null;
  const previewVisivel = fotoPreview ?? fotoAtual;

  useEffect(() => {
    if (!open) {
      return;
    }

    setData(editing ? editing.data.slice(0, 10) : (defaultData ?? todayISO()));
    setHorario(editing ? editing.horario.slice(0, 5) : horaAgora());
    setNome(editing?.nome ?? "");
    setTipo(editing?.tipo ?? "outro");
    setCalorias(editing ? String(editing.calorias) : "");
    setProteinas(editing?.proteinas_g ?? "");
    setCarboidratos(editing?.carboidratos_g ?? "");
    setGorduras(editing?.gorduras_g ?? "");
    setObservacao(editing?.observacao ?? "");
    setFormError(null);
    setDescricao("");
    setAnalise(null);
    setFoto(null);
    setFotoPreview(null);
    setRemoverFoto(false);
  }, [open, editing, defaultData]);

  // A URL do preview é criada na hora; sem revoke ela vaza a cada troca de foto.
  useEffect(() => {
    if (foto === null) {
      setFotoPreview(null);

      return;
    }

    const url = URL.createObjectURL(foto);
    setFotoPreview(url);

    return () => URL.revokeObjectURL(url);
  }, [foto]);

  function numeroOuNull(valor: string): number | null {
    if (!valor.trim()) {
      return null;
    }
    const n = Number(valor.replace(",", "."));
    return Number.isNaN(n) ? null : n;
  }

  function handleFotoEscolhida(event: React.ChangeEvent<HTMLInputElement>) {
    const arquivo = event.target.files?.[0] ?? null;
    event.target.value = "";

    if (arquivo === null) {
      return;
    }
    if (arquivo.size > MAX_FOTO_MB * 1024 * 1024) {
      appToast.error(`A foto precisa ter até ${MAX_FOTO_MB} MB.`);

      return;
    }

    setFoto(arquivo);
    setRemoverFoto(false);
    setFormError(null);
  }

  function limparFoto() {
    // Com uma foto recém-escolhida, o X só descarta a escolha e volta à gravada;
    // sem ela, marca a gravada para remoção ao salvar.
    if (foto !== null) {
      setFoto(null);

      return;
    }

    setRemoverFoto(editing?.foto_path != null);
  }

  async function handleAnalisar() {
    if (!descricao.trim() && foto === null) {
      setFormError("Descreva a refeição ou anexe uma foto do prato.");

      return;
    }

    setFormError(null);
    setAnalisando(true);
    try {
      const resultado = await analisarSaudeRefeicao(descricao, foto);

      if (!resultado.e_comida) {
        setAnalise(null);
        setFormError(
          "Não identifiquei comida aí. Ajuste a descrição, tente outra foto ou preencha os campos na mão.",
        );

        return;
      }

      setAnalise(resultado);
      setNome(resultado.nome);
      setTipo(resultado.tipo);
      setCalorias(String(resultado.calorias));
      setProteinas(paraCampo(resultado.proteinas_g));
      setCarboidratos(paraCampo(resultado.carboidratos_g));
      setGorduras(paraCampo(resultado.gorduras_g));
      appToast.success("Estimativa pronta — confira antes de confirmar.");
    } catch (error) {
      const message =
        error instanceof ApiError
          ? error.message
          : "Não foi possível analisar a refeição.";
      setFormError(message);
      appToast.error(message);
    } finally {
      setAnalisando(false);
    }
  }

  async function handleSubmit(event: React.FormEvent) {
    event.preventDefault();
    setFormError(null);

    const kcal = Number(calorias);
    if (!nome.trim()) {
      setFormError("Dê um nome à refeição.");
      return;
    }
    if (!calorias.trim() || Number.isNaN(kcal) || kcal < 0) {
      setFormError("Informe as calorias.");
      return;
    }

    const payload = {
      data,
      horario,
      nome: nome.trim(),
      tipo: (tipo || null) as SaudeRefeicaoTipo | null,
      calorias: Math.round(kcal),
      proteinas_g: numeroOuNull(proteinas),
      carboidratos_g: numeroOuNull(carboidratos),
      gorduras_g: numeroOuNull(gorduras),
      observacao: observacao.trim() || null,
      // O detalhamento da IA fica guardado como registro de como se chegou ao número.
      ...(analise
        ? {
            origem: "painel_ia" as const,
            confianca: analise.confianca,
            itens: analise.itens,
          }
        : {}),
    };

    setSaving(true);
    try {
      if (editing) {
        await updateSaudeRefeicao(editing.id, payload, { foto, removerFoto });
        appToast.success("Refeição atualizada.");
      } else {
        await createSaudeRefeicao(payload, foto);
        appToast.success("Refeição registrada.");
      }
      onOpenChange(false);
      onSaved();
    } catch (error) {
      const message =
        error instanceof ApiError
          ? error.message
          : "Não foi possível salvar a refeição.";
      setFormError(message);
      appToast.error(message);
    } finally {
      setSaving(false);
    }
  }

  return (
    <Sheet open={open} onOpenChange={onOpenChange}>
      <SheetContent className="w-full overflow-y-auto sm:max-w-md">
        <SheetHeader>
          <SheetTitle>{editing ? "Editar refeição" : "Registrar refeição"}</SheetTitle>
          <SheetDescription>
            {editing
              ? "Ajuste livremente — inclusive as estimativas feitas pela IA."
              : "Descreva o prato ou anexe a foto e deixe a IA estimar — você confere e confirma."}
          </SheetDescription>
        </SheetHeader>

        <form className="px-4 pb-4" onSubmit={handleSubmit}>
          <FieldGroup className="gap-4">
            <div className="space-y-3 rounded-lg border border-dashed p-3">
              <Field>
                <FieldLabel htmlFor="refeicao-descricao" className="items-center">
                  <Sparkles className="size-4 text-emerald-600" />
                  Descreva a refeição
                </FieldLabel>
                <textarea
                  id="refeicao-descricao"
                  value={descricao}
                  onChange={(e) => setDescricao(e.target.value)}
                  placeholder="2 ovos mexidos, 1 fatia de pão integral e café com leite"
                  rows={2}
                  maxLength={1000}
                  className="border-input focus-visible:border-ring focus-visible:ring-ring/50 min-h-16 w-full rounded-md border bg-transparent px-3 py-2 text-sm shadow-xs outline-none transition-[color,box-shadow] focus-visible:ring-[3px]"
                />
                <FieldDescription>
                  Com foto, a descrição vira complemento — conta o que a imagem não
                  mostra (óleo, açúcar, porção).
                </FieldDescription>
              </Field>

              {previewVisivel ? (
                <div className="flex items-center gap-3">
                  {/* eslint-disable-next-line @next/next/no-img-element */}
                  <img
                    src={previewVisivel}
                    alt="Foto da refeição"
                    className="size-16 shrink-0 rounded-md border object-cover"
                  />
                  <div className="min-w-0 flex-1">
                    <p className="truncate text-sm">{foto?.name ?? "Foto atual"}</p>
                    <p className="text-xs text-muted-foreground">
                      {foto ? tamanhoArquivo(foto.size) : "Anexada a este registro"}
                    </p>
                  </div>
                  <Button type="button" variant="ghost" size="icon" onClick={limparFoto}>
                    <X className="size-4" />
                    <span className="sr-only">Remover foto</span>
                  </Button>
                </div>
              ) : null}

              <input
                ref={fotoInputRef}
                type="file"
                accept="image/jpeg,image/png,image/webp,image/gif"
                className="hidden"
                onChange={handleFotoEscolhida}
              />

              <div className="flex flex-wrap gap-2">
                <Button
                  type="button"
                  variant="outline"
                  size="sm"
                  onClick={() => fotoInputRef.current?.click()}
                >
                  <ImagePlus className="size-4" />
                  {previewVisivel ? "Trocar foto" : "Anexar foto"}
                </Button>
                <Button
                  type="button"
                  size="sm"
                  onClick={handleAnalisar}
                  disabled={analisando || (!descricao.trim() && foto === null)}
                >
                  {analisando ? <Spinner data-icon="inline-start" /> : <Sparkles className="size-4" />}
                  {analisando ? "Analisando..." : "Estimar com IA"}
                </Button>
              </div>
            </div>

            {analise ? (
              <div className="space-y-2 rounded-lg border border-emerald-500/40 bg-emerald-500/5 p-3">
                <p className="text-sm font-medium">
                  Estimativa da IA · {CONFIANCA_LABEL[analise.confianca] ?? analise.confianca}
                </p>
                {analise.itens.length > 0 ? (
                  <ul className="space-y-1 text-xs text-muted-foreground">
                    {analise.itens.map((item, i) => (
                      <li key={`${item.nome}-${i}`} className="flex justify-between gap-3">
                        <span className="min-w-0 truncate">
                          {item.nome}
                          {item.quantidade ? ` — ${item.quantidade}` : ""}
                        </span>
                        <span className="shrink-0 tabular-nums">
                          {item.calorias.toLocaleString("pt-BR")} kcal
                        </span>
                      </li>
                    ))}
                  </ul>
                ) : null}
                <p className="text-xs text-muted-foreground">
                  Confira os campos abaixo e corrija o que precisar antes de confirmar.
                </p>
              </div>
            ) : null}

            <Field>
              <FieldLabel htmlFor="refeicao-nome">Refeição</FieldLabel>
              <Input
                id="refeicao-nome"
                value={nome}
                onChange={(e) => setNome(e.target.value)}
                placeholder="Frango grelhado com arroz e salada"
                required
              />
            </Field>

            <div className="grid grid-cols-3 gap-3">
              <Field>
                <FieldLabel htmlFor="refeicao-data">Data</FieldLabel>
                <Input
                  id="refeicao-data"
                  type="date"
                  value={data}
                  onChange={(e) => setData(e.target.value)}
                  required
                />
              </Field>
              <Field>
                <FieldLabel htmlFor="refeicao-horario">Horário</FieldLabel>
                <Input
                  id="refeicao-horario"
                  type="time"
                  value={horario}
                  onChange={(e) => setHorario(e.target.value)}
                  required
                />
              </Field>
              <Field>
                <FieldLabel htmlFor="refeicao-tipo">Tipo</FieldLabel>
                <Select value={tipo} onValueChange={setTipo}>
                  <SelectTrigger id="refeicao-tipo" className="w-full">
                    <SelectValue placeholder="Tipo" />
                  </SelectTrigger>
                  <SelectContent>
                    {TIPOS.map((t) => (
                      <SelectItem key={t.value} value={t.value}>
                        {t.label}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              </Field>
            </div>

            <div className="grid grid-cols-2 gap-3">
              <Field>
                <FieldLabel htmlFor="refeicao-calorias">Calorias (kcal)</FieldLabel>
                <Input
                  id="refeicao-calorias"
                  type="number"
                  min={0}
                  max={5000}
                  value={calorias}
                  onChange={(e) => setCalorias(e.target.value)}
                  placeholder="620"
                  required
                />
              </Field>
              <Field>
                <FieldLabel htmlFor="refeicao-proteinas">Proteínas (g)</FieldLabel>
                <Input
                  id="refeicao-proteinas"
                  type="number"
                  step="0.1"
                  min={0}
                  max={500}
                  value={proteinas}
                  onChange={(e) => setProteinas(e.target.value)}
                  placeholder="45"
                />
              </Field>
            </div>

            <div className="grid grid-cols-2 gap-3">
              <Field>
                <FieldLabel htmlFor="refeicao-carboidratos">Carboidratos (g)</FieldLabel>
                <Input
                  id="refeicao-carboidratos"
                  type="number"
                  step="0.1"
                  min={0}
                  value={carboidratos}
                  onChange={(e) => setCarboidratos(e.target.value)}
                  placeholder="55"
                />
              </Field>
              <Field>
                <FieldLabel htmlFor="refeicao-gorduras">Gorduras (g)</FieldLabel>
                <Input
                  id="refeicao-gorduras"
                  type="number"
                  step="0.1"
                  min={0}
                  value={gorduras}
                  onChange={(e) => setGorduras(e.target.value)}
                  placeholder="18"
                />
              </Field>
            </div>

            <Field>
              <FieldLabel htmlFor="refeicao-observacao">Observação</FieldLabel>
              <Input
                id="refeicao-observacao"
                value={observacao}
                onChange={(e) => setObservacao(e.target.value)}
                placeholder="Opcional"
              />
              <FieldDescription>
                Macros são opcionais — calorias bastam para o acompanhamento.
              </FieldDescription>
            </Field>

            <FieldError>{formError}</FieldError>
          </FieldGroup>

          <SheetFooter className="px-0">
            <Button
              type="submit"
              disabled={saving || analisando}
              className={cn(analise && "bg-emerald-600 hover:bg-emerald-700")}
            >
              {saving ? <Spinner data-icon="inline-start" /> : null}
              {editing
                ? "Salvar alterações"
                : analise
                  ? "Confirmar e registrar"
                  : "Registrar"}
            </Button>
          </SheetFooter>
        </form>
      </SheetContent>
    </Sheet>
  );
}

import { Spinner } from "@/components/ui/spinner";

type DashboardPageLoaderProps = {
  label?: string;
};

export function DashboardPageLoader({
  label = "Carregando painel...",
}: DashboardPageLoaderProps) {
  return (
    <div className="flex min-h-[220px] items-center justify-center rounded-xl border border-dashed border-border bg-muted/20 px-4">
      <div className="flex items-center gap-3 text-sm text-muted-foreground">
        <Spinner />
        <span>{label}</span>
      </div>
    </div>
  );
}

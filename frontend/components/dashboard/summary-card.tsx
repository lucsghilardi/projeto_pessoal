import { Card, CardContent, CardDescription, CardHeader } from "@/components/ui/card";

export function SummaryCard({
  label,
  value,
  accentClass,
  icon,
}: {
  label: string;
  value: string;
  accentClass?: string;
  icon?: React.ReactNode;
}) {
  return (
    <Card>
      <CardHeader className="pb-2">
        <CardDescription className="flex items-center gap-1">
          {icon}
          {label}
        </CardDescription>
      </CardHeader>
      <CardContent>
        <p className={`text-2xl font-semibold tabular-nums ${accentClass ?? ""}`}>{value}</p>
      </CardContent>
    </Card>
  );
}

import {
  Award,
  CalendarCheck,
  Crown,
  Flame,
  type LucideIcon,
  Medal,
  Rocket,
  Shield,
  Sparkles,
  Zap,
} from "lucide-react";

const ICONS: Record<string, LucideIcon> = {
  Sparkles,
  Rocket,
  Medal,
  Flame,
  Zap,
  CalendarCheck,
  Shield,
  Crown,
};

export function AchievementIcon({
  name,
  className,
}: {
  name: string;
  className?: string;
}) {
  const Icon = ICONS[name] ?? Award;
  return <Icon className={className} />;
}

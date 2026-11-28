import { type LucideIcon } from "lucide-react";
import {
  type RowActionSeverity,
  type SplittableAction,
} from "./splitRowActions";

export type { RowActionSeverity, SplittableAction };

export type RowActionTone = "neutral" | "info" | "accent" | "success";

export interface RowAction extends SplittableAction {
  label: string;
  icon: LucideIcon;
  onSelect: () => void;
  tone?: RowActionTone;
  disabled?: boolean;
  disabledReason?: string;
  loading?: boolean;
}

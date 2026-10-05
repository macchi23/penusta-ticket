import type { Allocation } from "@/lib/api";

export const ALLOCATION_COLORS: Record<Allocation, string> = {
  home: "#2563eb",
  away: "#dc2626",
  buffer: "#9ca3af",
  closed: "#374151",
};

export const ALLOCATION_LABELS: Record<Allocation, string> = {
  home: "ホーム",
  away: "アウェー",
  buffer: "緩衝帯（販売なし）",
  closed: "閉鎖",
};

export const ALLOCATIONS = Object.keys(ALLOCATION_LABELS) as Allocation[];

export function isSellable(allocation: Allocation): boolean {
  return allocation === "home" || allocation === "away";
}

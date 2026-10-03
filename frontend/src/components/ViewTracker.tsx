"use client";

import { useEffect, useRef } from "react";
import { recordView } from "@/lib/actions/events";

export default function ViewTracker({ matchId, blockCode }: { matchId: number; blockCode?: string }) {
  const last = useRef<string | null>(null);
  useEffect(() => {
    const key = `${matchId}:${blockCode ?? ""}`;
    if (last.current === key) return;
    last.current = key;
    // 記録の失敗は利用者に見せない（action 呼び出し自体の失敗も含む）
    recordView(matchId, blockCode).catch(() => {});
  }, [matchId, blockCode]);
  return null;
}

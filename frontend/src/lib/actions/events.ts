"use server";

import { apiCall } from "@/lib/server-api";

function isId(n: unknown): n is number {
  return typeof n === "number" && Number.isSafeInteger(n) && n > 0;
}

// 閲覧ログ。失敗しても呼び出し側には何も返さない
export async function recordView(matchId: number, blockCode?: string): Promise<void> {
  if (!isId(matchId)) return;
  const validCode =
    typeof blockCode === "string" && /^[A-Za-z0-9_-]{1,20}$/.test(blockCode) ? blockCode : null;
  const body = validCode
    ? { type: "view_seatmap", match_id: matchId, block_code: validCode }
    : { type: "view_match", match_id: matchId };
  try {
    await apiCall("POST", "/api/events", body);
  } catch {
    // 無視
  }
}

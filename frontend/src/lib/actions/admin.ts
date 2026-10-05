"use server";

import { refresh, revalidatePath } from "next/cache";
import type { ActionResult, Allocation, AllocationChange, AllocationUpdateResult } from "@/lib/api";
import { ALLOCATIONS } from "@/lib/allocation";
import { apiCall } from "@/lib/server-api";

function isId(n: unknown): n is number {
  return typeof n === "number" && Number.isSafeInteger(n) && n > 0;
}

function isAllocation(v: unknown): v is Allocation {
  return typeof v === "string" && (ALLOCATIONS as string[]).includes(v);
}

function invalid(): ActionResult<AllocationUpdateResult> {
  return { ok: false, error: { code: "validation_failed", message: "不正なリクエストです" } };
}

const REFRESH_ON_ERROR = ["allocation_conflict", "match_locked"];

export async function saveAllocations(
  matchId: number,
  changes: AllocationChange[],
): Promise<ActionResult<AllocationUpdateResult>> {
  if (!isId(matchId)) return invalid();
  if (!Array.isArray(changes) || changes.length < 1 || changes.length > 100) return invalid();
  const seen = new Set<number>();
  for (const c of changes) {
    if (!Number.isSafeInteger(c?.block_id) || seen.has(c.block_id)) return invalid();
    seen.add(c.block_id);
    if (!isAllocation(c.expected) || !isAllocation(c.allocation) || c.expected === c.allocation) {
      return invalid();
    }
  }
  const result = await apiCall<AllocationUpdateResult>("PATCH", `/api/admin/matches/${matchId}/allocations`, {
    changes: changes.map((c) => ({ block_id: c.block_id, expected: c.expected, allocation: c.allocation })),
  });
  if (result.ok || REFRESH_ON_ERROR.includes(result.error.code)) {
    revalidatePath("/matches/[id]", "page");
    refresh();
  }
  return result;
}

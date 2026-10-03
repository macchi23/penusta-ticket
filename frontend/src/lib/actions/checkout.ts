"use server";

import { redirect, RedirectType } from "next/navigation";
import type { ActionResult, Order } from "@/lib/api";
import { apiCall } from "@/lib/server-api";

function isId(n: unknown): n is number {
  return typeof n === "number" && Number.isSafeInteger(n) && n > 0;
}

export async function checkout(
  matchId: number,
  orderId: number,
): Promise<ActionResult<Order>> {
  if (!isId(matchId) || !isId(orderId)) {
    return { ok: false, error: { code: "validation_failed", message: "不正なリクエストです" } };
  }
  const result = await apiCall<{ order: Order }>("POST", `/api/orders/${orderId}/checkout`);
  if (!result.ok) return result;
  redirect(`/orders/${result.data.order.id}`, RedirectType.replace);
}

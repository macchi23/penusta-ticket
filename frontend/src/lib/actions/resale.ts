"use server";

import { refresh, revalidatePath } from "next/cache";
import { redirect, RedirectType } from "next/navigation";
import type { ActionResult, MyListing } from "@/lib/api";
import { apiCall } from "@/lib/server-api";

const REFRESH_ON_ERROR = ["listing_unavailable", "not_listable", "resale_closed", "not_owner", "own_listing"];

// 成功時は /tickets 配下を再検証（UI も更新される）。失敗時は状態が古い可能性があるので画面だけ再取得
function sync(ok: boolean, code?: string) {
  if (ok) revalidatePath("/tickets", "layout");
  else if (code && REFRESH_ON_ERROR.includes(code)) refresh();
}

function isId(n: unknown): n is number {
  return typeof n === "number" && Number.isSafeInteger(n) && n > 0;
}

function invalid(): { ok: false; error: { code: "validation_failed"; message: string } } {
  return { ok: false, error: { code: "validation_failed", message: "不正なリクエストです" } };
}

export async function listTicket(ticketId: number, price: number): Promise<ActionResult<{ listing: MyListing }>> {
  if (!isId(ticketId) || !isId(price)) return invalid();
  const result = await apiCall<{ listing: MyListing }>("POST", `/api/tickets/${ticketId}/listing`, { price });
  sync(result.ok, result.ok ? undefined : result.error.code);
  return result;
}

export async function cancelListing(ticketId: number): Promise<ActionResult<{ listing: MyListing }>> {
  if (!isId(ticketId)) return invalid();
  const result = await apiCall<{ listing: MyListing }>("DELETE", `/api/tickets/${ticketId}/listing`);
  sync(result.ok, result.ok ? undefined : result.error.code);
  return result;
}

export async function purchaseListing(listingId: number): Promise<ActionResult<never>> {
  if (!isId(listingId)) return invalid();
  const result = await apiCall<{ order: { id: number } }>("POST", `/api/resale-listings/${listingId}/purchase`);
  if (!result.ok) {
    sync(false, result.error.code);
    return result;
  }
  revalidatePath("/matches/[id]", "page");
  revalidatePath("/tickets", "layout");
  redirect(`/orders/${result.data.order.id}`, RedirectType.replace);
}

"use server";

import { refresh } from "next/cache";
import type { ActionResult, CartResponse } from "@/lib/api";
import { apiCall } from "@/lib/server-api";

const REFRESH_ON_ERROR = ["seat_unavailable", "sold_out", "cart_expired"];

function isId(n: unknown): n is number {
  return typeof n === "number" && Number.isSafeInteger(n) && n > 0;
}

function invalid(): ActionResult<CartResponse> {
  return { ok: false, error: { code: "validation_failed", message: "不正なリクエストです" } };
}

async function run(
  method: string,
  path: string,
  body?: unknown,
): Promise<ActionResult<CartResponse>> {
  const result = await apiCall<CartResponse>(method, path, body);
  if (result.ok || REFRESH_ON_ERROR.includes(result.error.code)) refresh();
  return result;
}

export async function holdSeat(matchId: number, seatId: number) {
  if (!isId(matchId) || !isId(seatId)) return invalid();
  return run("POST", `/api/matches/${matchId}/cart/items`, { seat_id: seatId });
}

export async function holdUnreserved(matchId: number, blockId: number) {
  if (!isId(matchId) || !isId(blockId)) return invalid();
  return run("POST", `/api/matches/${matchId}/cart/items`, { block_id: blockId });
}

export async function releaseItem(matchId: number, itemId: number) {
  if (!isId(matchId) || !isId(itemId)) return invalid();
  return run("DELETE", `/api/matches/${matchId}/cart/items/${itemId}`);
}

export async function changeTicketType(matchId: number, itemId: number, ticketTypeId: number) {
  if (!isId(matchId) || !isId(itemId) || !isId(ticketTypeId)) return invalid();
  return run("PATCH", `/api/matches/${matchId}/cart/items/${itemId}`, {
    ticket_type_id: ticketTypeId,
  });
}

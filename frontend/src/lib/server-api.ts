// サーバー専用: cookie を読んで Laravel を呼ぶ。Client Component から import しないこと。
import { cookies } from "next/headers";
import { apiBase } from "@/lib/api";
import type { ActionResult, AllocationConflict, ApiError, ApiErrorCode } from "@/lib/api";
import { DEMO_USER_COOKIE, isDemoAuthEnabled } from "@/lib/demo-auth";

// ApiErrorCode の全要素を過不足なく列挙させる（足りない / 余るとコンパイルエラー）
const API_ERROR_CODE_MAP: Record<ApiErrorCode, true> = {
  seat_unavailable: true, sold_out: true, cart_expired: true,
  cart_limit_reached: true, not_on_sale: true, price_not_found: true, cart_empty: true,
  payment_declined: true, user_required: true, validation_failed: true, unknown: true,
  allocation_conflict: true, match_locked: true, allocation_busy: true, busy: true, forbidden: true, not_eligible: true, seats_hidden: true,
  resale_closed: true, not_owner: true, not_listable: true, price_out_of_range: true,
  listing_unavailable: true, own_listing: true, resale_limit_reached: true, payment_failed: true,
};
const API_ERROR_CODES = Object.keys(API_ERROR_CODE_MAP) as ApiErrorCode[];

export class ApiHttpError extends Error {
  constructor(public status: number, path: string) {
    super(`API error ${status}: ${path}`);
  }
}

const SESSION_COOKIE = "penusta_sid";

async function buildHeaders(hasBody: boolean): Promise<Record<string, string>> {
  const store = await cookies();
  const headers: Record<string, string> = { Accept: "application/json" };
  const userId = isDemoAuthEnabled() ? store.get(DEMO_USER_COOKIE)?.value : undefined;
  if (userId) headers["X-Demo-User-Id"] = userId;
  const sid = store.get(SESSION_COOKIE)?.value;
  if (sid) headers["X-Session-Id"] = sid;
  if (hasBody) headers["Content-Type"] = "application/json";
  return headers;
}

// Server Component 用。2xx 以外は throw
export async function apiGet<T>(path: string): Promise<T> {
  const res = await fetch(`${apiBase()}${path}`, {
    cache: "no-store",
    headers: await buildHeaders(false),
  });
  if (!res.ok) {
    throw new ApiHttpError(res.status, path);
  }
  return res.json();
}

const FALLBACK_MESSAGES: Partial<Record<ApiErrorCode, string>> = {
  user_required: "ユーザーを選択してください",
  validation_failed: "入力内容が正しくありません",
  forbidden: "この操作の権限がありません",
  unknown: "エラーが発生しました",
};

function toApiError(status: number, body: unknown): ApiError {
  const b = (body ?? {}) as { message?: unknown; code?: unknown; details?: unknown };
  if (typeof b.code === "string" && (API_ERROR_CODES as readonly string[]).includes(b.code)) {
    const code = b.code as ApiErrorCode;
    const error: ApiError = {
      code,
      message: typeof b.message === "string" ? b.message : FALLBACK_MESSAGES[code] ?? "エラーが発生しました",
    };
    const blocks = (b.details as { blocks?: unknown } | null | undefined)?.blocks;
    if (code === "allocation_conflict" && Array.isArray(blocks)) {
      error.details = { blocks: blocks as AllocationConflict[] };
    }
    return error;
  }
  // 契約外の code は backend の message を載せない（例外内容の漏えい防止）
  const code: ApiErrorCode =
    status === 422 ? "validation_failed"
    : status === 401 ? "user_required"
    : status === 403 ? "forbidden"
    : "unknown";
  return { code, message: FALLBACK_MESSAGES[code]! };
}

// Server Action 用。失敗は throw せず ActionResult で返す
export async function apiCall<T>(
  method: string,
  path: string,
  body?: unknown,
): Promise<ActionResult<T>> {
  try {
    const res = await fetch(`${apiBase()}${path}`, {
      method,
      cache: "no-store",
      headers: await buildHeaders(body !== undefined),
      body: body !== undefined ? JSON.stringify(body) : undefined,
    });
    const json = await res.json().catch(() => null);
    if (!res.ok) {
      return { ok: false, error: toApiError(res.status, json) };
    }
    return { ok: true, data: json as T };
  } catch {
    return { ok: false, error: { code: "unknown", message: "通信に失敗しました" } };
  }
}

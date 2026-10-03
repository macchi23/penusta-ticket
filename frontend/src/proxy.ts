import { NextResponse } from "next/server";
import type { NextRequest } from "next/server";

const SESSION_COOKIE = "penusta_sid";
const UUID_RE = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;

// 行動ログ用のセッション ID を cookie で発行する。
export function proxy(request: NextRequest) {
  if (UUID_RE.test(request.cookies.get(SESSION_COOKIE)?.value ?? "")) {
    return NextResponse.next();
  }

  const sid = crypto.randomUUID();
  // 初回リクエストの描画で cookies() から読めるよう、リクエスト側にも入れる
  request.cookies.set(SESSION_COOKIE, sid);
  const response = NextResponse.next({ request: { headers: request.headers } });
  response.cookies.set({
    name: SESSION_COOKIE,
    value: sid,
    httpOnly: true,
    sameSite: "lax",
    path: "/",
    maxAge: 60 * 60 * 24 * 365,
  });
  return response;
}

export const config = {
  matcher: [
    {
      source: "/((?!_next/static|_next/image|favicon.ico).*)",
      missing: [
        { type: "header", key: "next-router-prefetch" },
        { type: "header", key: "purpose", value: "prefetch" },
      ],
    },
  ],
};

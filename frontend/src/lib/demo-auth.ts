// サーバー専用: demo 認証の有効判定と cookie 名。NEXT_PUBLIC_ を付けない env を使う。
export const DEMO_USER_COOKIE = "penusta_demo_user";

export function isDemoAuthEnabled(): boolean {
  return process.env.DEMO_AUTH_ENABLED === "true";
}

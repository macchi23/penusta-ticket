// サーバー専用: 管理画面の取得。ページ（Server Component）から await して呼ぶこと。
// 401 / 403 / 404 は Next.js の例外（unauthorized / forbidden / notFound）を投げる。
import { forbidden, notFound, unauthorized, unstable_rethrow } from "next/navigation";
import type { AdminAllocations, AdminMatch } from "@/lib/api";
import { ApiHttpError, apiGet } from "@/lib/server-api";

async function adminGet<T>(path: string): Promise<T> {
  try {
    return await apiGet<T>(path);
  } catch (e) {
    unstable_rethrow(e);
    if (e instanceof ApiHttpError) {
      if (e.status === 401) unauthorized();
      if (e.status === 403) forbidden();
      if (e.status === 404) notFound();
    }
    throw e;
  }
}

export async function getAdminMatches(): Promise<AdminMatch[]> {
  const res = await adminGet<{ data: AdminMatch[] }>("/api/admin/matches");
  return res.data;
}

export async function getAdminAllocations(matchId: number): Promise<AdminAllocations> {
  return adminGet<AdminAllocations>(`/api/admin/matches/${matchId}/allocations`);
}

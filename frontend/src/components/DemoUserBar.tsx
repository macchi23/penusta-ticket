import { cookies } from "next/headers";
import { ApiHttpError, apiGet } from "@/lib/server-api";
import { DEMO_USER_COOKIE, isDemoAuthEnabled } from "@/lib/demo-auth";
import type { DemoUser } from "@/lib/api";
import DemoUserSwitcher from "@/components/DemoUserSwitcher";

// 開発用のユーザー切替バー。demo 無効（frontend の env か backend の 404）なら描かない
export default async function DemoUserBar() {
  if (!isDemoAuthEnabled()) return null;

  const hasCookie = (await cookies()).has(DEMO_USER_COOKIE);
  const [usersRes, meRes] = await Promise.allSettled([
    apiGet<{ data: DemoUser[] }>("/api/demo/users?limit=200"),
    hasCookie ? apiGet<{ user: DemoUser }>("/api/me") : Promise.resolve(null),
  ]);

  let users: DemoUser[] = [];
  let listFailed = false;
  if (usersRes.status === "fulfilled") {
    users = usersRes.value.data;
  } else if (usersRes.reason instanceof ApiHttpError && usersRes.reason.status === 404) {
    return null;
  } else {
    listFailed = true;
  }

  const current = meRes.status === "fulfilled" ? (meRes.value?.user ?? null) : null;

  return (
    <div className="flex items-center justify-between gap-4 border-b border-amber-300 bg-amber-100 px-4 py-1 text-xs text-amber-900">
      <span>
        <span className="mr-2 font-semibold">DEV</span>
        {current ? current.name : "ユーザー未選択"}
        {listFailed && <span className="ml-3 text-red-700">ユーザー一覧を取得できません</span>}
      </span>
      <DemoUserSwitcher
        users={users.map((u) => ({ id: u.id, name: u.name }))}
        currentId={current?.id ?? null}
      />
    </div>
  );
}

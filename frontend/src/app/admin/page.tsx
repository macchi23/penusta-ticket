import Link from "next/link";
import { getAdminMatches } from "@/lib/admin-api";

function formatKickoff(iso: string): string {
  return new Date(iso).toLocaleString("ja-JP", {
    year: "numeric",
    month: "numeric",
    day: "numeric",
    weekday: "short",
    hour: "2-digit",
    minute: "2-digit",
    timeZone: "Asia/Tokyo",
  });
}

export default async function AdminPage() {
  const matches = await getAdminMatches();

  return (
    <main className="mx-auto max-w-3xl px-4 py-10">
      <Link href="/" className="text-sm text-blue-600 underline">
        ← 試合一覧
      </Link>
      <h1 className="mt-2 mb-6 text-2xl font-bold">管理画面: 席種割当</h1>
      {matches.length === 0 ? (
        <p className="text-gray-500">試合がありません。</p>
      ) : (
        <ul className="space-y-3">
          {matches.map((m) => (
            <li
              key={m.id}
              className="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-gray-200 p-4 dark:border-gray-700"
            >
              <div>
                <div className="text-xs text-gray-500">
                  {m.section_label} ・ {formatKickoff(m.kickoff_at)}
                </div>
                <div className="mt-1 flex items-center gap-3 font-semibold">
                  <span style={{ color: m.home_team.color }}>{m.home_team.name}</span>
                  <span className="text-sm text-gray-400">vs</span>
                  <span style={{ color: m.away_team.color }}>{m.away_team.name}</span>
                </div>
              </div>
              {m.editable ? (
                <Link
                  href={`/admin/matches/${m.id}/allocations`}
                  className="rounded bg-blue-600 px-3 py-1.5 text-sm text-white hover:bg-blue-700"
                >
                  割当を編集
                </Link>
              ) : (
                <span className="text-sm text-gray-500">編集不可（キックオフ済み）</span>
              )}
            </li>
          ))}
        </ul>
      )}
    </main>
  );
}
